<?php

declare(strict_types=1);

namespace Hermesi;

use Hermesi\Internal\Answer;
use Hermesi\Internal\Core;
use Hermesi\Internal\Json;
use Hermesi\Internal\Path;
use Hermesi\Internal\Recipients;
use Hermesi\Internal\Uuid;

final class Messages
{
    /** @internal */
    public function __construct(private readonly Core $core)
    {
    }

    /**
     * Send one message on one channel, through one published template. Almost everything should be an event: you say what
     * happened and Hermesi decides the channels. Use this when the channel is a requirement instead (an OTP that must be an SMS).
     *
     * It skips the workflow and nothing else: preferences, suppressions and the audit trail still apply, and a refused message is
     * a result you can read (`status` is `skipped` or `suppressed`), not an exception. A mistake (an unknown template, a template
     * with no variant for the channel, an unknown recipient) is thrown and creates nothing. There is no inline content: it would
     * put copy back in your code.
     *
     * **Pass your own `$idempotencyKey` when your code can run twice.** One is generated and kept across the retries if you give
     * none, so a timeout cannot send a second SMS, but only your own key survives your code running again.
     *
     * @param string                    $category a category key; its preference matrix applies, and a critical category bypasses preferences but never a suppression
     * @param array<string, mixed>|null $data     variables for the template, available there as `payload.*`; not kept once a provider has the message
     * @param string|null               $priority `critical`, `default` or `bulk`
     */
    public function send(
        string $channel,
        string|Subscriber $recipient,
        string $template,
        ?array $data = null,
        ?string $category = null,
        ?string $priority = null,
        ?string $idempotencyKey = null,
    ): MessageResult {
        if ('' === $channel) {
            throw new \InvalidArgumentException('channel is required, for example sms');
        }
        if ('' === $template) {
            throw new \InvalidArgumentException('template is required: the key of a published template');
        }
        $key = null === $idempotencyKey || '' === $idempotencyKey ? Uuid::v4() : $idempotencyKey;
        $body = [
            'channel' => Json::normalize($channel, 'channel'),
            'recipient' => Recipients::wire($recipient),
            'template' => Json::normalize($template, 'template'),
        ];
        if (null !== $category) {
            $body['category'] = Json::normalize($category, 'category');
        }
        if (null !== $data) {
            $body['data'] = Json::object($data, 'data');
        }
        if (null !== $priority) {
            $body['priority'] = Json::normalize($priority, 'priority');
        }

        return $this->core->call(
            'POST',
            '/v1/messages',
            $body,
            $key,
            static fn (int $n, array $sent): MessageResult => new MessageResult('msg_simulated_'.$n, 'simulated', [], false, $key),
            static fn (Answer $answer): MessageResult => MessageResult::fromWire(Core::object($answer, 'message_id'), $answer->replayed, $key),
        );
    }

    /** A message and how far it got. A NotFoundException for an unknown id or one of another environment. */
    public function get(string $messageId): Message
    {
        return $this->core->call(
            'GET',
            '/v1/messages/'.Path::segment($messageId, 'messageId'),
            null,
            null,
            null,
            static fn (Answer $answer): Message => Message::fromWire(Core::object($answer, 'id')),
        );
    }
}
