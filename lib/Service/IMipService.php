<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Nextcloud Mail iMIP Service - PARTIALLY IMPLEMENTED
 * 
 * This service handles iMIP (iCalendar Message-based Interoperability Protocol) messages
 * for automatic calendar scheduling. However, the implementation is INCOMPLETE:
 * 
 * TODO: Only processes messages with proper iMIP method parameter (REQUEST/REPLY/CANCEL)
 * TODO: Does NOT process regular ICS attachments from external calendar systems
 * TODO: Missing support for external calendar invitations sent as regular attachments
 * TODO: No fallback processing for ICS files without method parameter
 * TODO: Limited to MIME parts with method= parameter, missing many real-world invitations
 * 
 * The gap: External systems (Google Calendar, Outlook, etc.) often send ICS files as
 * regular attachments without proper iMIP formatting. These are currently ignored and
 * require manual user interaction to import.
 * 
 * See also: ImapMessageFetcher.php where external ICS attachments are detected but not processed
 */

namespace OCA\Mail\Service;

use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Model\IMAPMessage;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Calendar\IManager;
use Psr\Log\LoggerInterface;
use function array_filter;

class IMipService
{
	private AccountService $accountService;
	private IManager $calendarManager;
	private LoggerInterface $logger;
	private MailboxMapper $mailboxMapper;
	private MailManager $mailManager;
	private MessageMapper $messageMapper;

	public function __construct(
		AccountService $accountService,
		IManager $manager,
		LoggerInterface $logger,
		MailboxMapper $mailboxMapper,
		MailManager $mailManager,
		MessageMapper $messageMapper,
	) {
		$this->accountService = $accountService;
		$this->calendarManager = $manager;
		$this->logger = $logger;
		$this->mailboxMapper = $mailboxMapper;
		$this->mailManager = $mailManager;
		$this->messageMapper = $messageMapper;
	}

	public function process(): void
	{
		// TODO: This only processes internal iMIP messages with proper method parameter
		// TODO: Add processing for external ICS attachments (see ImapMessageFetcher logs)
		$this->logger->debug('JUN: Starting iMIP message processing (internal calendar invitations)');
		$messages = $this->messageMapper->findIMipMessagesAscending();
		$this->logger->debug('JUN: Found ' . count($messages) . ' iMIP messages to process (internal calendar invitations)');
		if ($messages === []) {
			$this->logger->debug('JUN: No iMIP messages to process (internal calendar invitations)');
			return;
		}

		// Collect all mailboxes in memory
		// possible perf improvement - make this one IN query
		// and JOIN with accounts table
		// although this might not make much of a difference
		// since there are very few messages to process
		$mailboxIds = array_unique(array_map(static fn(Message $message) => $message->getMailboxId(), $messages));

		$mailboxes = array_map(function (int $mailboxId) {
			try {
				return $this->mailboxMapper->findById($mailboxId);
			} catch (DoesNotExistException | ServiceException $e) {
				return null;
			}
		}, $mailboxIds);
		$existingMailboxes = array_filter($mailboxes);

		// Collect all accounts in memory
		$accountIds = array_unique(array_map(static fn(Mailbox $mailbox) => $mailbox->getAccountId(), $existingMailboxes));

		$accounts = array_combine($accountIds, array_map(function (int $accountId) {
			try {
				return $this->accountService->findById($accountId);
			} catch (DoesNotExistException $e) {
				return null;
			}
		}, $accountIds));

		foreach ($existingMailboxes as $mailbox) {
			/** @var Account $account */
			$account = $accounts[$mailbox->getAccountId()];
			$filteredMessages = array_filter($messages, static fn($message) => $message->getMailboxId() === $mailbox->getId());

			if ($filteredMessages === []) {
				continue;
			}

			// Check for accounts or mailboxes that no longer exist,
			// no processing for drafts, sent items, junk or archive
			if (
				$account === null
				|| $account->getMailAccount()->getArchiveMailboxId() === $mailbox->getId()
				|| $account->getMailAccount()->getSnoozeMailboxId() === $mailbox->getId()
				|| $account->getMailAccount()->getTrashMailboxId() === $mailbox->getId()
				|| $account->getMailAccount()->getSentMailboxId() === $mailbox->getId()
				|| $account->getMailAccount()->getDraftsMailboxId() === $mailbox->getId()
				|| $mailbox->isSpecialUse(\Horde_Imap_Client::SPECIALUSE_ARCHIVE)
			) {
				$processedMessages = array_map(static function (Message $message) {
					$message->setImipProcessed(true);
					return $message;
				}, $filteredMessages); // Silently drop from passing to DAV and mark as processed, so we won't run into these messages again.
				$this->messageMapper->updateImipData(...$processedMessages);
				continue;
			}

			try {
				$imapMessages = $this->mailManager->getImapMessagesForScheduleProcessing($account, $mailbox, array_map(static fn($message) => $message->getUid(), $filteredMessages));
			} catch (ServiceException $e) {
				$this->logger->error('Could not get IMAP messages form IMAP server', ['exception' => $e]);
				continue;
			}

			$principalUri = 'principals/users/' . $account->getUserId();
			$recipient = $account->getEmail();

			foreach ($filteredMessages as $message) {
				/** @var IMAPMessage $imapMessage */
				$imapMessage = current(array_filter($imapMessages, static fn(IMAPMessage $imapMessage) => $message->getUid() === $imapMessage->getUid()));
				if (empty($imapMessage->scheduling)) {
					// TODO: This message was flagged as iMIP but has no scheduling data
					// TODO: Could this be an external ICS attachment that was misclassified?
					// TODO: Consider fallback processing for ICS attachments without method parameter
					$message->setImipError(true);
					continue;
				}

				$sender = $imapMessage->getFrom()->first()?->getEmail();
				if ($sender === null) {
					$message->setImipError(true);
					continue;
				}

				foreach ($imapMessage->scheduling as $schedulingInfo) { // an IMAP message could contain more than one iMIP object
					if ($schedulingInfo['method'] === 'REQUEST') {
						$this->logger->debug('JUN: Processing internal iMIP REQUEST', [
							'sender' => $sender,
							'recipient' => $recipient,
							'principalUri' => $principalUri,
							'messageId' => $message->getId(),
						]);
						$processed = $this->calendarManager->handleIMipRequest($principalUri, $sender, $recipient, $schedulingInfo['contents']);
						$this->logger->debug('JUN: Internal iMIP REQUEST processed', [
							'processed' => $processed,
							'sender' => $sender,
							'recipient' => $recipient,
						]);
						$message->setImipProcessed($processed);
						$message->setImipError(!$processed);
					} elseif ($schedulingInfo['method'] === 'REPLY') {
						$this->logger->debug('JUN: Processing internal iMIP REPLY', [
							'sender' => $sender,
							'recipient' => $recipient,
							'principalUri' => $principalUri,
							'messageId' => $message->getId(),
						]);
						$processed = $this->calendarManager->handleIMipReply($principalUri, $sender, $recipient, $schedulingInfo['contents']);
						$this->logger->debug('JUN: Internal iMIP REPLY processed', [
							'processed' => $processed,
							'sender' => $sender,
							'recipient' => $recipient,
						]);
						$message->setImipProcessed($processed);
						$message->setImipError(!$processed);
					} elseif ($schedulingInfo['method'] === 'CANCEL') {
						$replyTo = $imapMessage->getReplyTo()->first()?->getEmail();
						$this->logger->debug('JUN: Processing internal iMIP CANCEL', [
							'sender' => $sender,
							'replyTo' => $replyTo,
							'recipient' => $recipient,
							'principalUri' => $principalUri,
							'messageId' => $message->getId(),
						]);
						$processed = $this->calendarManager->handleIMipCancel($principalUri, $sender, $replyTo, $recipient, $schedulingInfo['contents']);
						$this->logger->debug('JUN: Internal iMIP CANCEL processed', [
							'processed' => $processed,
							'sender' => $sender,
							'replyTo' => $replyTo,
							'recipient' => $recipient,
						]);
						$message->setImipProcessed($processed);
						$message->setImipError(!$processed);
					}
				}
			}
			$this->messageMapper->updateImipData(...$filteredMessages);
		}
	}
}
