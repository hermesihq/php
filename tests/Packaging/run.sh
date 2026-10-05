#!/bin/sh
# What `composer require hermesihq/hermesi` serves, as opposed to this repository.
#
# Every other test here resolves the package through this repository's own autoloader, so they prove the source works and say nothing
# about the archive. A `composer.json` that requires the wrong thing, a file the archive leaves out, a namespace that does not autoload
# from the installed layout: each installs without a word and fails at the consumer's first request.
#
# So this builds the archive the way Packagist does (`composer archive` honours .gitattributes), installs it into a throwaway project
# that has a PSR-18 client and nothing else of ours, and uses it. A second project has no HTTP client at all.
set -eu

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
fail() { echo "FAIL  $1"; exit 1; }
ok() { echo "  ok    $1"; }

cd "$ROOT"
composer archive --format=zip --dir="$WORK" --file=pkg --quiet
mkdir "$WORK/pkg" && (cd "$WORK/pkg" && unzip -q ../pkg.zip)
ok "the archive builds"

# The archive is what is served: the tests and the tooling are not in it.
for leaked in tests .github phpunit.xml.dist phpstan.neon.dist .php-cs-fixer.dist.php; do
  [ ! -e "$WORK/pkg/$leaked" ] || fail "the archive contains $leaked"
done
for needed in src/Hermesi.php README.md LICENSE CHANGELOG.md composer.json; do
  [ -e "$WORK/pkg/$needed" ] || fail "the archive lacks $needed"
done
ok "the archive carries the source, README, LICENSE and CHANGELOG, and no tests or tooling"

# A consumer with Guzzle.
mkdir "$WORK/with-client" && cd "$WORK/with-client"
composer init --no-interaction --name=consumer/app --quiet
composer config repositories.hermesi '{"type":"path","url":"'"$WORK"'/pkg","options":{"symlink":false,"versions":{"hermesihq/hermesi":"0.1.0"}}}'
composer config --no-plugins allow-plugins.php-http/discovery true
composer require --no-interaction --quiet hermesihq/hermesi:0.1.0 guzzlehttp/guzzle
ok "installs next to Guzzle with nothing else of ours"

cat > use.php <<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';

use Hermesi\Exception\ConnectionException;
use Hermesi\Hermesi;
use Hermesi\RetryPolicy;

$h = new Hermesi(simulate: true);
$h->events->trigger('order.shipped', 'user_1', ['order_id' => '1']);
if ('order.shipped' !== $h->simulated()[0]->name) { fwrite(STDERR, "simulate failed\n"); exit(1); }

// Discovery finds Guzzle, builds it, and a dead address is a ConnectionException, not a Guzzle one.
$real = new Hermesi(apiKey: 'hm_sk_x', baseUrl: 'http://127.0.0.1:1', retry: new RetryPolicy(maxRetries: 0), timeout: 2.0);
try {
    $real->events->trigger('order.shipped', 'user_1');
    fwrite(STDERR, "no exception\n"); exit(1);
} catch (ConnectionException) {
    echo "connection exception\n";
}
$token = $h->tokens->mint('user_1', environmentId: 'env_1');
if (1 !== preg_match('/^[\w-]+\.[\w-]+$/', $token)) { fwrite(STDERR, "bad token\n"); exit(1); }
PHP
[ "$(php use.php)" = "connection exception" ] || fail "using the installed package"
ok "used through the installed autoloader: simulate, a real attempt through discovered Guzzle, a token"

# A consumer with no PSR-18 client at all: either Composer's discovery plugin picks one, or the first request says what to install.
mkdir "$WORK/no-client" && cd "$WORK/no-client"
composer init --no-interaction --name=consumer/bare --quiet
composer config repositories.hermesi '{"type":"path","url":"'"$WORK"'/pkg","options":{"symlink":false,"versions":{"hermesihq/hermesi":"0.1.0"}}}'
composer config --no-plugins allow-plugins.php-http/discovery false
if composer require --no-interaction --quiet hermesihq/hermesi:0.1.0 2>"$WORK/err.txt"; then
  # Installed with no client present: the runtime message is the only help, so it has to be there.
  cat > bare.php <<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/vendor/autoload.php';
try {
    (new Hermesi\Hermesi(apiKey: 'hm_sk_x', baseUrl: 'http://127.0.0.1:1'))->events->trigger('a.b', 'u');
} catch (Hermesi\Exception\HermesiException $e) {
    echo str_contains($e->getMessage(), 'composer require') ? "helpful\n" : "unhelpful: ".$e->getMessage()."\n";
}
PHP
  [ "$(php bare.php)" = "helpful" ] || fail "no client installed and the error does not say what to install"
  ok "with no HTTP client the first request says what to install"
else
  grep -q "psr/http-client-implementation\|psr/http-factory-implementation" "$WORK/err.txt" || { cat "$WORK/err.txt"; fail "Composer refused without naming the missing implementation"; }
  ok "with no HTTP client Composer refuses and names the implementation it needs"
fi

# A consumer with no client who lets Composer's discovery plugin act: `composer require hermesihq/hermesi` alone must end with
# something that works, which is what "works with any HTTP client" has to mean for someone who has none.
mkdir "$WORK/auto" && cd "$WORK/auto"
composer init --no-interaction --name=consumer/auto --quiet
composer config repositories.hermesi '{"type":"path","url":"'"$WORK"'/pkg","options":{"symlink":false,"versions":{"hermesihq/hermesi":"0.1.0"}}}'
composer config --no-plugins allow-plugins.php-http/discovery true
composer require --no-interaction --quiet hermesihq/hermesi:0.1.0
cp "$WORK/with-client/use.php" use.php
[ "$(php use.php)" = "connection exception" ] || fail "the client the discovery plugin installed does not work"
ok "with no client of its own, composer require alone installs one and the package works through it"

echo
echo "hermesihq/hermesi ok: what Composer would serve installs and works."
