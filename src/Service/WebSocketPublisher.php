<?php

declare(strict_types=1);

namespace NChat\Service;

/**
 * WebSocket publisher — Pusher/Soketi-compatible event publishing and auth.
 *
 * Abstraction over Soketi/Pusher HTTP API for triggering events
 * and authenticating private/presence channel subscriptions.
 */
class WebSocketPublisher
{
	public function __construct(
		private string $appId,
		private string $appKey,
		private string $appSecret,
		private string $host = 'soketi',
		private int $port = 6001,
	) {}


	/**
	 * Trigger an event on a channel.
	 *
	 * @param array<string, mixed> $data
	 */
	public function trigger(string $channel, string $event, array $data): void
	{
		$body = json_encode([
			'name' => $event,
			'channel' => $channel,
			'data' => json_encode($data) ?: '{}',
		]) ?: '{}';

		$path = '/apps/' . $this->appId . '/events';
		$queryParams = $this->buildAuthQuery('POST', $path, $body);

		$url = "http://{$this->host}:{$this->port}" . $path . '?' . http_build_query($queryParams);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
			CURLOPT_POSTFIELDS => $body,
		]);

		curl_exec($ch);
		curl_close($ch);
	}


	/**
	 * Authenticate a private or presence channel subscription.
	 *
	 * @param array{user_id: string, user_info: array{name: string}}|null $userData
	 * @return string JSON auth response for Pusher client
	 */
	public function auth(string $socketId, string $channel, ?array $userData = null): string
	{
		if ($userData !== null) {
			// Presence channel
			$stringToSign = $socketId . ':' . $channel . ':' . (json_encode($userData) ?: '{}');
			$signature = hash_hmac('sha256', $stringToSign, $this->appSecret);

			return json_encode([
				'auth' => $this->appKey . ':' . $signature,
				'channel_data' => json_encode($userData) ?: '{}',
			]) ?: '{}';
		}

		// Private channel
		$stringToSign = $socketId . ':' . $channel;
		$signature = hash_hmac('sha256', $stringToSign, $this->appSecret);

		return json_encode([
			'auth' => $this->appKey . ':' . $signature,
		]) ?: '{}';
	}


	/**
	 * Get the app key (for JS client config).
	 */
	public function getAppKey(): string
	{
		return $this->appKey;
	}


	/**
	 * Build Pusher-compatible auth query parameters.
	 *
	 * @return array<string, string>
	 */
	private function buildAuthQuery(string $method, string $path, string $body): array
	{
		$params = [
			'auth_key' => $this->appKey,
			'auth_timestamp' => (string) time(),
			'auth_version' => '1.0',
			'body_md5' => md5($body),
		];

		ksort($params);
		$queryString = http_build_query($params);

		$signString = "{$method}\n{$path}\n{$queryString}";
		$params['auth_signature'] = hash_hmac('sha256', $signString, $this->appSecret);

		return $params;
	}
}
