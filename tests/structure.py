from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
plugin=(root/'open-signage-studio.php').read_text()
core=(root/'includes/class-osgs.php').read_text()
telegram=(root/'includes/class-osgs-telegram.php').read_text()
assert 'Plugin Name: OpenSignage Studio' in plugin
assert 'GPLv2 or later' in plugin
assert '0.1.0-beta.1' in plugin
assert "const NS = 'open-signage/v1'" in core
assert 'screen_allowed' in core and 'quick_allowed' in core
assert 'private static function register(' in core and 'callable $callback' in core
assert "add_rewrite_rule('^signage-screen/?$'" in core
assert "add_rewrite_rule('^signage-control/?$'" in core
assert 'x-telegram-bot-api-secret-token' in telegram
assert 'hash_equals' in telegram
assert re.search(r'register_rest_route\(',core)
assert (root/'LICENSE').is_file()
print('Structure checks passed')
