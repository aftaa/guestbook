<?php

namespace App\MessageHandler;

use App\ImageOptimizer;
use App\Message\CommentMessage;
use App\Notification\CommentReviewNotification;
use App\Repository\CommentRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class CommentMessageHandler implements MessageHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SpamChecker $spamChecker,
        private CommentRepository $commentRepository,
        private MessageBusInterface $bus,
        private WorkflowInterface $commentStateMachine,
        private MailerInterface $mailer,
        private ImageOptimizer $imageOptimizer,
        private string $adminEmail,
        private string $photoDir,
        private NotifierInterface $notifier,
        private ?LoggerInterface $logger = null,
    )
    {
    }

    /**
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function __invoke(CommentMessage $message)
    {
        $comment = $this->commentRepository->find($message->getId());
        if (!$comment) {
            return;
        }

        if ($this->commentStateMachine->can($comment, 'accept')) {
            $score = $this->spamChecker->getSpamScore($comment, $message->getContext());
            $transition = 'accept';
            if (2 === $score) {
                $transition = 'reject_spam';
            } else {
                $transition = 'might_be_spam';
            }
            $this->commentStateMachine->apply($comment, $transition);
            $this->entityManager->flush();

            $this->bus->dispatch($message);
        } elseif ($this->commentStateMachine->can($comment, 'publish') || $this->commentStateMachine->can('publish_ham')) {
            $this->mailer->send((new NotificationEmail())
                ->subject('New comment posted')
                ->htmlTemplate('emails/comment_notification.html.twig')
                ->from($this->adminEmail)
                ->to($this->adminEmail)
                ->context(['comment' => $comment])
            );

            $this->notifier->send(new CommentReviewNotification($comment), ...$this->notifier->getAdminRecipients());

        } elseif ($this->commentStateMachine->can($comment, 'optimize')) {
            if ($comment->getPhotoFilename()) {
                $this->imageOptimizer->resize($this->photoDir . '/' . $comment->getPhotoFilename());
            }
            $this->commentStateMachine->apply($comment, 'optimize');
            $this->mailer->send((new NotificationEmail())
                ->subject('Your comment is published')
                ->htmlTemplate('emails/comment_published.html.twig')
                ->from($this->adminEmail)
                ->to($comment->getEmail())
                ->context(['comment' => $comment])
            );
        } else {
            $this->logger->debug('Dropping comment message', ['comment' => $comment->getId(), 'state' => $comment->getState()]);
        }
    }
}
