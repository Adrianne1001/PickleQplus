<?php

namespace App\Notifications;

use App\Models\ClubInvitation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent on demand to the invited address. Deliberately not queued, so the
 * plain token never sits in the jobs table.
 */
class ClubInvitationNotification extends Notification
{
    public function __construct(
        public readonly ClubInvitation $invitation,
        public readonly string $plainToken,
        public readonly string $clubName,
        public readonly ?string $inviterName,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return route('invitations.show', ['token' => $this->plainToken]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = self::escapeMarkdown($this->inviterName ?? 'A club owner');
        $club = self::escapeMarkdown($this->clubName);

        return (new MailMessage)
            ->subject("You're invited to join {$this->clubName} on ".config('app.name'))
            ->line("{$who} invited you to join {$club} as {$this->invitation->role->value}.")
            ->action('View invitation', $this->url())
            ->line('This link expires on '.$this->invitation->expires_at->toFormattedDayDateString().' and can be used once.')
            ->line('Log in or register with this email address ('.$this->invitation->email.') to accept.');
    }

    /**
     * Backslash-escape Markdown control characters, so user-supplied text
     * (club and inviter names) renders literally in MailMessage lines.
     *
     * Mail Markdown runs htmlspecialchars on every line before CommonMark, so
     * & < > are already neutral and must not be escaped here (that would
     * double-encode them). Lines are joined with spaces, so block constructs
     * (headings, quotes, lists) only matter at the very start of the string.
     */
    public static function escapeMarkdown(string $text): string
    {
        $text = (string) preg_replace('/([\\\\`*_\[\]~])/', '\\\\$1', $text);

        return (string) preg_replace(['/^([#>+\-=])/', '/^(\d+)([.)])/'], ['\\\\$1', '$1\\\\$2'], $text);
    }
}
