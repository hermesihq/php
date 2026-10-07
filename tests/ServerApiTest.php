<?php

declare(strict_types=1);

namespace Hermesi\Tests;

use Hermesi\Exception\ApiException;
use Hermesi\Exception\ConflictException;
use Hermesi\Exception\NotFoundException;
use Hermesi\Exception\SimulationException;
use Hermesi\Exception\ValidationException;
use Hermesi\Hermesi;
use Hermesi\RetryPolicy;
use Hermesi\Subscriber;
use Hermesi\Tests\Support\TestServer;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServerApiTest extends ServerTestCase
{
    private const PROFILE = [
        'id' => 'sub_1',
        'external_id' => 'user_8821',
        'email' => 'amina@example.cm',
        'phone_e164' => '+237690000000',
        'first_name' => 'Amina',
        'last_name' => null,
        'locale' => 'fr',
        'timezone' => 'Africa/Douala',
        'avatar_url' => null,
        'data' => ['plan' => 'pro'],
        'created_at' => '2026-10-01T10:00:00Z',
        'updated_at' => '2026-10-02T10:00:00Z',
        'channels' => [
            ['channel' => 'push', 'identifier' => 'tok_1', 'state' => 'active', 'state_reason' => null, 'metadata' => ['platform' => 'android'], 'verified_at' => null, 'last_used_at' => null],
        ],
        'preferences' => ['global' => ['sms' => false], 'categories' => ['marketing' => ['email' => false]]],
    ];

    private const RUN = [
        'event_id' => 'evt_1',
        'name' => 'order.shipped',
        'status' => 'processed',
        'payload' => ['orderId' => '4821'],
        'actor' => null,
        'idempotency_key' => 'k1',
        'error' => null,
        'received_at' => '2026-10-01T10:00:00Z',
        'processed_at' => '2026-10-01T10:00:01Z',
        'notifications' => [[
            'id' => 'not_1',
            'subscriber_id' => 'sub_1',
            'external_id' => 'user_8821',
            'workflow' => 'order-shipped',
            'workflow_version' => 3,
            'status' => 'waiting',
            'created_at' => '2026-10-01T10:00:00Z',
            'started_at' => '2026-10-01T10:00:01Z',
            'completed_at' => null,
            'resume_at' => '2026-10-01T10:15:00Z',
            'messages' => [
                ['id' => 'msg_1', 'channel' => 'sms', 'status' => 'delivered', 'step_key' => 'sms', 'provider' => 'twilio', 'failure_code' => null, 'failure_message' => null, 'created_at' => '2026-10-01T10:00:02Z', 'terminal_at' => '2026-10-01T10:00:09Z'],
                ['id' => 'msg_2', 'channel' => 'email', 'status' => 'queued', 'step_key' => 'email', 'provider' => null, 'failure_code' => null, 'failure_message' => null, 'created_at' => '2026-10-01T10:00:02Z', 'terminal_at' => null],
            ],
        ]],
    ];

    private const SENT = ['message_id' => 'msg_1', 'status' => 'queued', 'messages' => [['id' => 'msg_1', 'channel' => 'sms', 'status' => 'queued', 'reason' => null]]];

    protected function setUp(): void
    {
        parent::setUp();
        self::$server->setDefault(['status' => 200, 'body' => new \stdClass()]);
    }

    // --- events->get ---------------------------------------------------------------------------

    public function testReadsTheRunWithTheMessagesFlattenedAndFinalOnesMarked(): void
    {
        self::$server->enqueue(['body' => self::RUN]);

        $run = $this->client()->events->get('evt_1');

        $request = self::$server->last();
        self::assertSame('GET', $request['method']);
        self::assertSame('/v1/events/evt_1', $request['path']);
        self::assertSame('Bearer '.self::KEY, $request['headers']['authorization']);
        self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        self::assertArrayNotHasKey('content-type', $request['headers']);
        self::assertSame('', $request['body']);
        self::assertSame('processed', $run->status);
        self::assertSame('user_8821', $run->notifications[0]->externalId);
        self::assertSame('order-shipped', $run->notifications[0]->workflow);
        self::assertSame(3, $run->notifications[0]->workflowVersion);
        self::assertSame('2026-10-01T10:15:00Z', $run->notifications[0]->resumeAt);
        self::assertSame([['msg_1', true], ['msg_2', false]], array_map(static fn ($m): array => [$m->id, $m->isFinal], $run->messages()));
        self::assertSame('twilio', $run->messages()[0]->provider);
        self::assertSame('sms', $run->messages()[0]->stepKey);
    }

    public function testEscapesTheEventIdAndRefusesDotSegments(): void
    {
        self::$server->enqueue(['body' => self::RUN]);
        $this->client()->events->get('a/b?c');
        self::assertSame('/v1/events/a%2Fb%3Fc', self::$server->last()['path']);

        foreach (['..', ''] as $bad) {
            try {
                $this->client()->events->get($bad);
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount(1, self::$server->requests());
    }

    public function testAnEventOfAnotherEnvironmentIsANotFoundException(): void
    {
        self::$server->enqueue(['status' => 404, 'body' => TestServer::error('event_not_found')]);

        $this->expectException(NotFoundException::class);

        $this->client()->events->get('evt_x');
    }

    public function testAnAnswerThatIsNotAnEventIsAnUnexpectedResponse(): void
    {
        self::$server->enqueue(['body' => ['nope' => true]]);

        $this->expectExceptionMessage('unexpected_response');

        $this->client()->events->get('evt_1');
    }

    public function testAReadIsRetriedOnA503(): void
    {
        self::$server->enqueue(['status' => 503, 'body' => TestServer::error('unavailable')], ['body' => self::RUN]);

        $run = $this->client(new RetryPolicy(maxRetries: 2))->events->get('evt_1');

        self::assertSame('evt_1', $run->eventId);
        self::assertCount(2, self::$server->requests());
    }

    // --- subscribers put / patch ---------------------------------------------------------------

    public function testPutSendsWhatWasGivenInSnakeCaseAndReadsTheProfile(): void
    {
        self::$server->enqueue(['body' => self::PROFILE]);

        $profile = $this->client()->subscribers->put('user_8821', [
            'email' => 'amina@example.cm',
            'phone_e164' => '+237690000000',
            'first_name' => 'Amina',
            'data' => ['plan' => 'pro'],
        ]);

        $request = self::$server->last();
        self::assertSame('PUT', $request['method']);
        self::assertSame('/v1/subscribers/user_8821', $request['path']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame(['email' => 'amina@example.cm', 'phone_e164' => '+237690000000', 'first_name' => 'Amina', 'data' => ['plan' => 'pro']], $this->lastBody());
        self::assertSame('user_8821', $profile->externalId);
        self::assertSame('+237690000000', $profile->phoneE164);
        self::assertNull($profile->lastName);
        self::assertSame('Africa/Douala', $profile->timezone);
        self::assertSame(['plan' => 'pro'], $profile->data);
        self::assertSame('tok_1', $profile->channels[0]->identifier);
        self::assertSame(['platform' => 'android'], $profile->channels[0]->metadata);
        self::assertSame(['sms' => false], $profile->preferences->global);
        self::assertSame(['marketing' => ['email' => false]], $profile->preferences->categories);
    }

    public function testAKeyLeftOutIsNotSentAndNullIsSentToClear(): void
    {
        self::$server->enqueue(['body' => self::PROFILE]);

        $this->client()->subscribers->put('user_8821', ['locale' => 'en', 'phone_e164' => null]);

        self::assertSame(['locale' => 'en', 'phone_e164' => null], $this->lastBody());
    }

    public function testWithNoFieldAtAllItSendsAnEmptyObjectNotAnEmptyList(): void
    {
        self::$server->enqueue(['body' => self::PROFILE]);

        $this->client()->subscribers->put('user_8821');

        self::assertSame('{}', self::$server->last()['body']);
    }

    public function testAnEmptyDataReplacesWithAnEmptyObject(): void
    {
        self::$server->enqueue(['body' => self::PROFILE]);

        $this->client()->subscribers->put('user_8821', ['data' => []]);

        self::assertSame('{"data":{}}', self::$server->last()['body']);
    }

    public function testPatchUsesPatch(): void
    {
        self::$server->enqueue(['body' => self::PROFILE]);

        $this->client()->subscribers->patch('user_8821', ['locale' => 'fr']);

        self::assertSame('PATCH', self::$server->last()['method']);
    }

    public function testRefusesAKeyItDoesNotKnowRatherThanDroppingItSilently(): void
    {
        try {
            $this->client()->subscribers->put('user_8821', ['phoneE164' => '+1']);
            self::fail('accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('unknown subscriber field "phoneE164"', $e->getMessage());
        }
        self::assertSame([], self::$server->requests());
    }

    public function testRefusesAValueOfTheWrongType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('email must be a string');

        $this->client()->subscribers->put('user_8821', ['email' => 42]); // @phpstan-ignore argument.type
    }

    public function testAnIdWithASlashGoesInAsOneSegment(): void
    {
        self::$server->enqueue(['body' => self::PROFILE]);

        $this->client()->subscribers->put('team/42 é?#');

        self::assertSame('/v1/subscribers/team%2F42%20%C3%A9%3F%23', self::$server->last()['path']);
    }

    public function testARefusedFieldIsAValidationException(): void
    {
        self::$server->enqueue(['status' => 422, 'body' => TestServer::error('validation_error', ['detail' => [['field' => 'body.email', 'issue' => 'invalid']]])]);

        try {
            $this->client()->subscribers->put('user_8821', ['email' => 'nope']);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertSame('body.email', $e->details[0]->field);
        }
    }

    public function testPatchOfAnUnknownSubscriberIsANotFoundException(): void
    {
        self::$server->enqueue(['status' => 404, 'body' => TestServer::error('subscriber_not_found')]);

        $this->expectException(NotFoundException::class);

        $this->client()->subscribers->patch('nobody', ['locale' => 'fr']);
    }

    public function testAPutIsRetriedBecauseItIsIdempotent(): void
    {
        self::$server->enqueue(['status' => 502, 'body' => new \stdClass()], ['body' => self::PROFILE]);

        $this->client(new RetryPolicy(maxRetries: 2))->subscribers->put('user_8821', ['locale' => 'fr']);

        self::assertCount(2, self::$server->requests());
    }

    public function testABodyThatCannotBeEncodedFailsBeforeAnythingIsSent(): void
    {
        try {
            $this->client()->subscribers->put('user_8821', ['data' => ['x' => \NAN]]);
            self::fail('accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], self::$server->requests());
        }
    }

    // --- get / delete --------------------------------------------------------------------------

    public function testGetReadsTheProfile(): void
    {
        self::$server->enqueue(['body' => self::PROFILE]);

        $profile = $this->client()->subscribers->get('user_8821');

        $request = self::$server->last();
        self::assertSame(['GET', '/v1/subscribers/user_8821', ''], [$request['method'], $request['path'], $request['body']]);
        self::assertSame(['plan' => 'pro'], $profile->data);
    }

    public function testGetOfAnUnknownSubscriberIsANotFoundException(): void
    {
        self::$server->enqueue(['status' => 404, 'body' => TestServer::error('subscriber_not_found')]);

        $this->expectException(NotFoundException::class);

        $this->client()->subscribers->get('nobody');
    }

    public function testDeleteAnswers204WithNoBody(): void
    {
        self::$server->enqueue(['status' => 204, 'body' => '']);

        $this->client()->subscribers->delete('user_8821');

        $request = self::$server->last();
        self::assertSame(['DELETE', '/v1/subscribers/user_8821', ''], [$request['method'], $request['path'], $request['body']]);
    }

    // --- channels ------------------------------------------------------------------------------

    public function testRegistersADestination(): void
    {
        self::$server->enqueue(['status' => 201, 'body' => self::PROFILE['channels'][0]]);

        $identity = $this->client()->subscribers->registerChannel('user_8821', 'push', 'tok_1', ['platform' => 'android']);

        $request = self::$server->last();
        self::assertSame(['POST', '/v1/subscribers/user_8821/channels'], [$request['method'], $request['path']]);
        self::assertSame(['channel' => 'push', 'identifier' => 'tok_1', 'metadata' => ['platform' => 'android']], $this->lastBody());
        self::assertSame(['push', 'tok_1', 'active'], [$identity->channel, $identity->identifier, $identity->state]);
    }

    public function testLeavesMetadataOutWhenThereIsNone(): void
    {
        self::$server->enqueue(['body' => self::PROFILE['channels'][0]]);

        $this->client()->subscribers->registerChannel('user_8821', 'push', 'tok_1');

        self::assertSame(['channel' => 'push', 'identifier' => 'tok_1'], $this->lastBody());
    }

    public function testRequiresAChannelAndAnIdentifier(): void
    {
        foreach ([['', 'tok'], ['push', '']] as [$channel, $identifier]) {
            try {
                $this->client()->subscribers->registerChannel('user_8821', $channel, $identifier);
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], self::$server->requests());
    }

    public function testRemovesOneWithTheIdentifierAsASingleEscapedSegment(): void
    {
        self::$server->enqueue(['status' => 204, 'body' => '']);

        $this->client()->subscribers->removeChannel('user_8821', 'webpush', 'https://push.example/send/abc?x=1#y');

        $request = self::$server->last();
        self::assertSame('DELETE', $request['method']);
        self::assertSame('/v1/subscribers/user_8821/channels/webpush/https%3A%2F%2Fpush.example%2Fsend%2Fabc%3Fx%3D1%23y', $request['path']);
    }

    /** @return iterable<string, array{string}> */
    public static function dotSegments(): iterable
    {
        yield 'one dot' => ['.'];
        yield 'two dots' => ['..'];
    }

    #[DataProvider('dotSegments')]
    public function testRefusesADotSegmentAsAnIdentifier(string $identifier): void
    {
        try {
            $this->client()->subscribers->removeChannel('user_8821', 'push', $identifier);
            self::fail('accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], self::$server->requests());
        }
    }

    // --- preferences ---------------------------------------------------------------------------

    public function testReadsTheOverrides(): void
    {
        self::$server->enqueue(['body' => self::PROFILE['preferences']]);

        $preferences = $this->client()->subscribers->preferences('user_8821');

        $request = self::$server->last();
        self::assertSame(['GET', '/v1/subscribers/user_8821/preferences'], [$request['method'], $request['path']]);
        self::assertSame(['email' => false], $preferences->categories['marketing']);
    }

    public function testUpdatesThemWithPatchAndNullGoesOutToRemoveAnOverride(): void
    {
        self::$server->enqueue(['body' => ['global' => ['sms' => false], 'categories' => ['marketing' => ['email' => false]]]]);

        $this->client()->subscribers->updatePreferences('user_8821', global: ['sms' => false], categories: ['marketing' => ['email' => false, 'push' => null]]);

        $request = self::$server->last();
        self::assertSame(['PATCH', '/v1/subscribers/user_8821/preferences'], [$request['method'], $request['path']]);
        self::assertSame(['global' => ['sms' => false], 'categories' => ['marketing' => ['email' => false, 'push' => null]]], $this->lastBody());
    }

    public function testSendsOnlyTheGroupThatWasGiven(): void
    {
        self::$server->enqueue(['body' => ['global' => [], 'categories' => []]]);

        $this->client()->subscribers->updatePreferences('user_8821', global: ['sms' => true]);

        self::assertSame(['global' => ['sms' => true]], $this->lastBody());
    }

    public function testAnEmptyGroupIsAnObjectNotAList(): void
    {
        self::$server->enqueue(['body' => ['global' => [], 'categories' => []]]);

        $this->client()->subscribers->updatePreferences('user_8821', global: [], categories: []);

        self::assertSame('{"global":{},"categories":{}}', self::$server->last()['body']);
    }

    public function testRefusesAnEmptyChangeAndAFlagThatIsNotABooleanBeforeSending(): void
    {
        foreach ([[null, null], [['sms' => 'false'], null], [null, ['marketing' => ['email' => 0]]]] as [$global, $categories]) {
            try {
                $this->client()->subscribers->updatePreferences('user_8821', $global, $categories); // @phpstan-ignore-line
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], self::$server->requests());
    }

    public function testACriticalCategoryIsAValidationException(): void
    {
        self::$server->enqueue(['status' => 422, 'body' => TestServer::error('category_not_overridable')]);

        $this->expectException(ValidationException::class);

        $this->client()->subscribers->updatePreferences('user_8821', categories: ['security' => ['sms' => false]]);
    }

    // --- messages ------------------------------------------------------------------------------

    public function testPostsTheMessageWithOneIdempotencyKeyAndReadsTheResult(): void
    {
        self::$server->enqueue(['status' => 202, 'body' => self::SENT]);

        $result = $this->client()->messages->send('sms', 'user_8821', 'otp-code', data: ['code' => '480219'], category: 'security', priority: 'critical', idempotencyKey: 'otp-user_8821-482');

        $request = self::$server->last();
        self::assertSame(['POST', '/v1/messages'], [$request['method'], $request['path']]);
        self::assertSame('otp-user_8821-482', $request['headers']['idempotency-key']);
        self::assertSame(
            ['channel' => 'sms', 'recipient' => 'user_8821', 'template' => 'otp-code', 'category' => 'security', 'data' => ['code' => '480219'], 'priority' => 'critical'],
            $this->lastBody(),
        );
        self::assertSame(['msg_1', 'queued', false, 'otp-user_8821-482'], [$result->messageId, $result->status, $result->replayed, $result->idempotencyKey]);
        self::assertSame(['msg_1', 'sms', 'queued', null], [$result->messages[0]->id, $result->messages[0]->channel, $result->messages[0]->status, $result->messages[0]->reason]);
    }

    public function testDescribesAnInlineRecipientOnTheWire(): void
    {
        self::$server->enqueue(['status' => 202, 'body' => self::SENT]);

        $this->client()->messages->send('sms', new Subscriber('u1', phoneE164: '+237690000000'), 'otp-code');

        self::assertSame(['external_id' => 'u1', 'phone_e164' => '+237690000000'], $this->lastBody()['recipient']);
    }

    public function testGeneratesAKeyWhenNoneIsGivenAndKeepsItAcrossARetry(): void
    {
        self::$server->enqueue(['status' => 503, 'body' => TestServer::error('unavailable')], ['status' => 202, 'body' => self::SENT]);

        $result = $this->client(new RetryPolicy(maxRetries: 2))->messages->send('sms', 'u1', 'otp-code');

        $keys = array_map(static fn (array $r): string => $r['headers']['idempotency-key'] ?? '', self::$server->requests());
        self::assertCount(2, $keys);
        self::assertNotSame('', $keys[0]);
        self::assertSame($keys[0], $keys[1]);
        self::assertSame($keys[0], $result->idempotencyKey);
    }

    public function testFlagsAReplayedAnswer(): void
    {
        self::$server->enqueue(['status' => 202, 'body' => self::SENT, 'headers' => ['Idempotency-Replayed' => 'true']]);

        self::assertTrue($this->client()->messages->send('sms', 'u1', 'otp-code')->replayed);
    }

    public function testARefusedMessageIsAResultNotAnException(): void
    {
        self::$server->enqueue(['status' => 202, 'body' => ['message_id' => 'msg_1', 'status' => 'skipped', 'messages' => [['id' => 'msg_1', 'channel' => 'sms', 'status' => 'skipped', 'reason' => 'preference_off']]]]);

        $result = $this->client()->messages->send('sms', 'u1', 'promo');

        self::assertSame(['skipped', 'preference_off'], [$result->status, $result->messages[0]->reason]);
    }

    public function testReusingAKeyWithAnotherBodyIsAConflictException(): void
    {
        self::$server->enqueue(['status' => 409, 'body' => TestServer::error('idempotency_key_reused')]);

        $this->expectException(ConflictException::class);

        $this->client()->messages->send('sms', 'u1', 'otp-code', idempotencyKey: 'k');
    }

    public function testAnUnknownTemplateIsANotFoundExceptionAndIsNotRetried(): void
    {
        self::$server->enqueue(['status' => 404, 'body' => TestServer::error('template_not_found')]);

        try {
            $this->client(new RetryPolicy(maxRetries: 2))->messages->send('sms', 'u1', 'nope');
            self::fail('accepted');
        } catch (NotFoundException) {
            self::assertCount(1, self::$server->requests());
        }
    }

    public function testRequiresAChannelAndATemplate(): void
    {
        foreach ([['', 'u1', 't'], ['sms', 'u1', '']] as [$channel, $recipient, $template]) {
            try {
                $this->client()->messages->send($channel, $recipient, $template);
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], self::$server->requests());
    }

    public function testAnAnswerWithoutAMessageIdIsAnUnexpectedResponse(): void
    {
        self::$server->enqueue(['status' => 202, 'body' => ['nope' => 1]]);

        $this->expectExceptionMessage('unexpected_response');

        $this->client()->messages->send('sms', 'u1', 't');
    }

    public function testReadsOneMessage(): void
    {
        self::$server->enqueue(['body' => self::RUN['notifications'][0]['messages'][0]]);

        $message = $this->client()->messages->get('msg_1');

        $request = self::$server->last();
        self::assertSame(['GET', '/v1/messages/msg_1'], [$request['method'], $request['path']]);
        self::assertSame(['msg_1', 'delivered', true], [$message->id, $message->status, $message->isFinal]);
    }

    public function testAnUnknownMessageIsANotFoundException(): void
    {
        self::$server->enqueue(['status' => 404, 'body' => TestServer::error('message_not_found')]);

        $this->expectException(NotFoundException::class);

        $this->client()->messages->get('msg_x');
    }

    public function testEveryRefusalOfTheNewCallsIsAnApiException(): void
    {
        self::$server->enqueue(['status' => 401, 'body' => TestServer::error('invalid_api_key')]);

        $this->expectException(ApiException::class);

        $this->client()->subscribers->get('u');
    }

    // --- simulate ------------------------------------------------------------------------------

    public function testSimulateRecordsTheWritesAsTheyWouldHaveGoneOutAndAnswersPlausibly(): void
    {
        $test = new Hermesi(simulate: true);

        $profile = $test->subscribers->put('user_8821', ['email' => 'a@example.cm', 'phone_e164' => null]);
        $message = $test->messages->send('sms', 'user_8821', 'otp-code', idempotencyKey: 'k1');
        $identity = $test->subscribers->registerChannel('user_8821', 'push', 'tok', ['platform' => 'ios']);
        $preferences = $test->subscribers->updatePreferences('user_8821', categories: ['marketing' => ['email' => false, 'push' => null]]);
        $test->subscribers->delete('user_8821');

        self::assertSame(['user_8821', 'a@example.cm'], [$profile->externalId, $profile->email]);
        self::assertSame(['simulated', 'k1'], [$message->status, $message->idempotencyKey]);
        self::assertSame(['push', 'tok', ['platform' => 'ios']], [$identity->channel, $identity->identifier, $identity->metadata]);
        self::assertSame(['marketing' => ['email' => false]], $preferences->categories);
        $calls = $test->simulatedCalls();
        self::assertSame(
            [['PUT', '/v1/subscribers/user_8821'], ['POST', '/v1/messages'], ['POST', '/v1/subscribers/user_8821/channels'], ['PATCH', '/v1/subscribers/user_8821/preferences'], ['DELETE', '/v1/subscribers/user_8821']],
            array_map(static fn ($c): array => [$c->method, $c->path], $calls),
        );
        self::assertSame(['email' => 'a@example.cm', 'phone_e164' => null], $calls[0]->body);
        self::assertSame('k1', $calls[1]->idempotencyKey);
        self::assertNull($calls[4]->body);
        self::assertSame([], $test->simulated());
    }

    public function testASimulatedReadThrowsAndRecordsNothing(): void
    {
        $test = new Hermesi(simulate: true);

        $reads = [
            static fn () => $test->events->get('evt_1'),
            static fn () => $test->subscribers->get('u'),
            static fn () => $test->subscribers->preferences('u'),
            static fn () => $test->messages->get('m'),
        ];
        foreach ($reads as $read) {
            try {
                $read();
                self::fail('a simulated read answered');
            } catch (SimulationException) {
            }
        }
        self::assertSame([], $test->simulatedCalls());
    }

    public function testASimulatedCallValidatesExactlyAsARealOneDoes(): void
    {
        $test = new Hermesi(simulate: true);
        $calls = [
            static fn () => $test->subscribers->put('user_8821', ['nope' => 1]),
            static fn () => $test->subscribers->put('..'),
            static fn () => $test->messages->send('sms', 'u', ''),
            static fn () => $test->messages->send('sms', 'u', 't', data: ['x' => \NAN]),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('accepted');
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertSame([], $test->simulatedCalls());
    }
}
