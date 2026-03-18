# NChat — Real-time Admin Chat for Nette Framework

Reusable real-time chat extension for Nette Framework admin panels with WebSocket support (Soketi/Pusher), typing indicators, presence detection, read receipts, emoji reactions, sound alerts, and browser push notifications.

## Features

- 💬 **Broadcast + DM + Group chat**
- ⚡ **WebSocket real-time** (Soketi/Pusher) with polling fallback
- ✍️ **Typing indicators** — "Jan píše…"
- 👥 **Online presence** — instant online/offline
- ✓✓ **Read receipts** — DM delivery confirmation
- 😂 **Emoji reactions** — 👍❤️😂😮😢🙏
- 🔔 **Sound alerts** — Web Audio API ping (toggleable)
- 📢 **Push notifications** — Browser Notification API
- 🤖 **AI Bot integration** — pluggable via `AiResponderInterface`
- 🗄️ **Database agnostic** — `ChatStorageInterface` (DBAL included)

## Installation

```json
{
    "repositories": [
        {"type": "vcs", "url": "git@gitlab.com:JanSuchanek/nchat.git"}
    ],
    "require": {
        "jansuchanek/nchat": "dev-main"
    }
}
```

## Configuration

```neon
extensions:
    nchat: NChat\ChatExtension

nchat:
    websocket:
        host: soketi
        port: 6001
        appId: my-app
        key: my-key
        secret: my-secret
    storage: NChat\Storage\DbalChatStorage  # or your own
    ai:
        enabled: true
        responder: App\Model\MyAiResponder  # implements AiResponderInterface
```

## Usage

Add the trait to any presenter:

```php
final class AdminPresenter extends BasePresenter
{
    use NChat\Presenter\ChatPresenterTrait;
}
```

Then add chat action names to your allowed actions:

```php
$openActions = array_merge(['dashboard'], self::getChatActionNames());
```

## Custom Database Storage

Implement `ChatStorageInterface` for any database:

```php
class SqliteChatStorage implements ChatStorageInterface
{
    public function saveMessage(array $data): int { /* ... */ }
    public function fetchMessages(int $userId, array $criteria = []): array { /* ... */ }
    // ...8 methods total
}
```

## Custom AI Responder

```php
class MyAiResponder implements AiResponderInterface
{
    public function respond(string $context): string { /* call OpenAI/Claude/Gemini */ }
    public function getSystemPrompt(): string { return 'You are...'; }
    public function getBotName(): string { return '🤖 Bot'; }
}
```

## License

MIT
