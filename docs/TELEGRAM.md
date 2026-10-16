# Telegram integration

Telegram support is optional. Use a separate bot for staging.

1. Create a bot with `@BotFather` and copy its token **privately**.
2. In WordPress open **OpenSignage Studio → Telegram** and enter the token.
3. Send `/start` to the bot to retrieve your Chat ID and User ID. Restrict both IDs in the plugin settings. Never post them in issues.
4. Use **Register secure webhook**. Telegram calls your site's HTTPS `/wp-json/open-signage/v1/telegram/webhook` route and sends the configured secret token header.
5. Send `/start` again to open the compact inline menu. Set up optional lunch/back-soon one-tap messages and durations in WordPress.

The bot supports `/start`, `/status`, `/slides`, `/message`, `/clear`, `/pause`, `/resume` and contextual inline buttons. The command `/message 10m Closed for lunch` displays an announcement for 10 minutes. For selected blinking text use `[blink]word[/blink]` and `[soft]word[/soft]` in a message.

**Important:** Telegram allows one active webhook per bot; registering it from another environment disables the previous webhook. Rotate a token immediately if leaked. The bot's user allowlist is recommended in private chats and required in groups.
