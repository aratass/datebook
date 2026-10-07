#!/usr/bin/env bash
# Checks the Craft Plugin Store approval items that can be checked from the repository:
# matching name, package and handle; a name that does not start with "Craft"; own icons;
# a documentation URL and a changelog with dated versions; the Craft License for a paid
# plugin; stable dependencies; no own licensing code and no calls home.
#
# In GitHub Actions it also checks that the documentation and changelog URLs return 200.
# Usage: ci/check-approval.sh   (PHP picks the PHP binary, default php)
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php="${PHP:-php}"
cd "$root"

# The PHP code is in single quotes on purpose: the shell must not expand it.
# shellcheck disable=SC2016
"$php" -d display_errors=stderr -r '
$errors = [];
$notes = [];
$fail = function (string $message) use (&$errors) { $errors[] = $message; };

// composer.json
$composer = json_decode(file_get_contents("composer.json"), true);
if (!is_array($composer)) {
    fwrite(STDERR, "composer.json is not valid JSON\n");
    exit(1);
}
$extra = $composer["extra"] ?? [];
$package = (string)($composer["name"] ?? "");
$handle = (string)($extra["handle"] ?? "");
$name = (string)($extra["name"] ?? "");

if (($composer["type"] ?? "") !== "craft-plugin") $fail("composer.json type must be craft-plugin.");
if (!preg_match("#^[a-z0-9-]+/([a-z0-9-]+)$#", $package, $m)) $fail("Package name \"$package\" is not vendor/package.");
if (!preg_match("/^[a-z][a-z0-9-]*$/", $handle)) $fail("Handle \"$handle\" must be kebab-case.");
if (isset($m[1]) && $m[1] !== $handle && $m[1] !== "craft-$handle") $fail("Package name \"$package\" does not match handle \"$handle\".");
if ($name === "") $fail("extra.name is missing.");
if (strtolower(preg_replace("/[^a-z0-9]+/i", "-", $name)) !== $handle) $fail("Plugin name \"$name\" does not match handle \"$handle\".");
if (preg_match("/^craft/i", $name)) $fail("Plugin name \"$name\" must not begin with Craft.");
if (empty($extra["developer"])) $fail("extra.developer is missing.");
foreach (["documentationUrl", "changelogUrl"] as $key) {
    if (!preg_match("#^https://#", (string)($extra[$key] ?? ""))) $fail("extra.$key must be an https URL.");
}
$class = (string)($extra["class"] ?? "");
$psr4 = $composer["autoload"]["psr-4"] ?? [];
$found = false;
foreach ($psr4 as $prefix => $dir) {
    if ($class !== "" && str_starts_with($class, $prefix)) {
        $file = rtrim($dir, "/") . "/" . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
        $found = is_file($file);
    }
}
if (!$found) $fail("extra.class \"$class\" does not point to a file.");

// License
$license = $composer["license"] ?? "";
if ($license === "proprietary") {
    $text = is_file("LICENSE.md") ? file_get_contents("LICENSE.md") : "";
    if (!str_contains($text, "The Craft License")) $fail("A paid plugin needs the Craft License in LICENSE.md.");
    if (str_contains($text, "[YOUR_NAME_HERE]")) $fail("LICENSE.md still has [YOUR_NAME_HERE].");
    if (!preg_match("/^Copyright © \S.+$/mu", $text)) $fail("LICENSE.md needs a copyright line.");
} elseif ($license === "MIT") {
    if (!is_file("LICENSE.md")) $fail("LICENSE.md is missing.");
} else {
    $fail("license must be \"proprietary\" (Craft License) or MIT, found \"" . json_encode($license) . "\".");
}

// Dependencies: a minimum Craft version and nothing unstable.
$require = $composer["require"] ?? [];
if (!preg_match("/^\^5\.\d+\.\d+/", (string)($require["craftcms/cms"] ?? ""))) $fail("craftcms/cms must be required with a minimum Craft 5 version, like ^5.5.0.");
foreach ($require as $dep => $constraint) {
    if (preg_match("/dev-|@dev|@alpha|@beta|@rc|^\*$/i", (string)$constraint)) $fail("Unstable or open constraint for $dep: $constraint");
}
if (isset($composer["minimum-stability"]) && $composer["minimum-stability"] !== "stable") $fail("minimum-stability must be stable.");

// Changelog: every version heading has a valid date.
$changelog = is_file("CHANGELOG.md") ? file("CHANGELOG.md", FILE_IGNORE_NEW_LINES) : [];
if (!$changelog) $fail("CHANGELOG.md is missing.");
$versions = [];
foreach ($changelog as $line) {
    if (!str_starts_with($line, "## ")) continue;
    if (!preg_match("/^## (\d+\.\d+\.\d+(?:\.\d+)?(?:-(?:alpha|beta)\.\d+|-RC\d+)?) - (\d{4})-(\d{2})-(\d{2})$/", $line, $v)
        || !checkdate((int)$v[3], (int)$v[4], (int)$v[2])) {
        $fail("Changelog heading is not \"## X.Y.Z - YYYY-MM-DD\": $line");
        continue;
    }
    $versions[] = $v[1];
}
if (!$versions) $fail("CHANGELOG.md has no version headings.");
$tag = trim((string)@shell_exec("git describe --tags --exact-match 2>/dev/null"));
if ($tag !== "" && $versions && ltrim(preg_replace("/^release-/", "", $tag), "v") !== $versions[0]) {
    $fail("Tag $tag does not match the newest changelog version " . $versions[0] . ".");
}
$notes[] = "Newest changelog version: " . ($versions[0] ?? "none") . ($tag !== "" ? ", tag $tag" : ", no tag on this commit");

// Icons: own square SVGs without scripts or remote references.
foreach (["src/icon.svg", "src/icon-mask.svg"] as $icon) {
    $svg = is_file($icon) ? file_get_contents($icon) : "";
    $xml = $svg !== "" ? @simplexml_load_string($svg) : false;
    if (!$xml || $xml->getName() !== "svg") { $fail("$icon is missing or not an SVG."); continue; }
    $box = preg_split("/[\s,]+/", trim((string)$xml["viewBox"]));
    if (count($box) !== 4 || (float)$box[2] <= 0 || (float)$box[2] !== (float)$box[3]) $fail("$icon must be square (viewBox).");
    if (preg_match("/<script|href=\"https?:|url\(\s*[\"\x27]?https?:/i", $svg)) $fail("$icon must not contain scripts or remote references.");
}

// Docs and store texts.
if (!is_file("README.md")) $fail("README.md is missing.");
foreach (["README.md", "CHANGELOG.md", "docs/plugin-store.md"] as $doc) {
    if (is_file($doc) && preg_match("/\bA\.?I\.?\b|artificial intelligence|ChatGPT|\bClaude\b|\bLLM\b|\bGPT\b/", file_get_contents($doc))) {
        $fail("$doc mentions AI. Store and documentation texts must not.");
    }
}

// No own licensing, no calls home.
$code = "";
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("src", FilesystemIterator::SKIP_DOTS)) as $file) {
    if (preg_match("/\.(php|twig|js)$/", $file->getFilename())) $code .= file_get_contents($file->getPathname()) . "\n";
}
foreach ([
    "/curl_init|fsockopen|stream_socket_client|createGuzzleClient|GuzzleHttp|new\s+Client\s*\(/" => "makes HTTP requests",
    "/file_get_contents\(\s*[\"\x27]https?:/" => "downloads from the web",
    "/\bfetch\(\s*[\"\x27]https?:|XMLHttpRequest/" => "calls remote URLs from the browser",
    "/license[_ ]?key|licenseKey|activateLicense|LICENSE_SERVER/i" => "has its own licensing code",
] as $pattern => $problem) {
    if (preg_match($pattern, $code)) $fail("src/ $problem. Use Craft licensing only and send nothing to the vendor.");
}

foreach ($notes as $note) echo "note: $note\n";
if ($errors) {
    foreach ($errors as $error) echo "FAIL: $error\n";
    exit(1);
}
echo "All Plugin Store checks passed for $name ($package, handle $handle).\n";
'

# In GitHub Actions the repository is public, so the URLs must answer.
if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
  for key in documentationUrl changelogUrl; do
    url=$("$php" -r 'echo json_decode(file_get_contents("composer.json"), true)["extra"]["'"$key"'"] ?? "";')
    code=$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 30 "$url" || true)
    if [ "$code" != "200" ]; then
      echo "FAIL: $key $url returned $code"
      exit 1
    fi
    echo "ok: $key $url returned 200"
  done
fi
