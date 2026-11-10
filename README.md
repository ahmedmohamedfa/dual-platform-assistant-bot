# Dual-Platform Assistant Bot (Telegram + WhatsApp)

A complete, production-ready dual-platform assistant bot built as a single PHP file for shared hosting.

## Features

- **Dual Platform Support**: Telegram (inline keyboards, Markdown) + WhatsApp (text menus)
- **Smart NLU**: Keyword-based intent detection in Arabic and English
- **Multi-step Conversations**: State machine for forms, feedback, and surveys
- **Session Management**: User state tracking via local JSON file database
- **Admin Panel**: Stats, broadcast, ban/unban, logs, user list — all via chat commands
- **Security**: Webhook secret validation, rate limiting, input sanitization, ban system
- **Logging**: Rotating log system (keeps last 1000 entries)
- **No Dependencies**: Pure PHP, no Composer required
- **PHP 7.4+ Compatible**: Includes polyfills for str_contains and str_starts_with

## Quick Start

1. Edit `bot.php` and fill in your configuration at the top:
   - `TELEGRAM_BOT_TOKEN` — from @BotFather
   - `WHATSAPP_API_KEY` and `WHATSAPP_INSTANCE_ID` — from UltraMsg
   - `ADMIN_CHAT_ID` — your Telegram user ID
   - `WEBHOOK_SECRET` — optional security token

2. Upload `bot.php` to your hosting

3. Set Telegram Webhook:
   ```
   https://api.telegram.org/bot{TOKEN}/setWebhook?url=https://yourdomain.com/bot.php
   ```

4. Set WhatsApp Webhook in your provider dashboard to:
   ```
   https://yourdomain.com/bot.php?platform=whatsapp
   ```

5. Ensure file permissions: `chmod 755 bot.php` and `chmod 666 bot_log.txt bot_data.json`

## Commands

### User Commands
| Command | Description |
|---------|-------------|
| `/start` | Welcome message with main menu |
| `/help` | Full help guide |
| `/about` | Bot information |
| `/settings` | User preferences (language, notifications) |
| `/feedback` | Send feedback to admin |
| `/cancel` | Cancel current operation |

### Admin Commands
| Command | Description |
|---------|-------------|
| `/admin` | Admin panel |
| `/stats` | Bot usage statistics |
| `/broadcast` | Send message to all users |
| `/ban [user_id]` | Ban a user |
| `/unban [user_id]` | Unban a user |
| `/logs` | View recent logs |
| `/users` | List all users |

## License

MIT
