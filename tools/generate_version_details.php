<?php
/**
 * =============================================================================
 * Generate Version Details
 * =============================================================================
 *
 * Reads git commits and generates a version changelog entry for version2.php.
 *
 * HOW IT WORKS
 * ------------
 * Running WITHOUT --write previews and saves to:
 *   version-details/version-preview/preview.html
 *
 * Running WITH --write reads that preview and applies it to:
 *   models/semantic-versioning/version2.php
 *
 * =============================================================================
 * HOW TO USE
 * ----------
 *
 * 1. php generate_version_details.php --count=40
 *    Preview the last 40 commits.
 *    Saves the HTML to version-details/version-preview/preview.html
 *    (No changes made to version2.php)
 *
 * 2. php generate_version_details.php --write
 *    Applies the LAST saved preview to version2.php.
 *    Always run step 1 first to check the preview, then step 2 to write.
 *
 * 3. php generate_version_details.php --count=40 --write
 *    Combine preview + write in one step.
 *    Useful when you're confident the commits look right.
 *    Internally: saves preview, THEN applies it.
 *
 * 4. php generate_version_details.php --from=abc1234
 *    Reads commits FROM that hash UP TO HEAD (newest).
 *    Think of it as: "start reading from this commit going forward."
 *    Example commit list (newest first):
 *      7th commit
 *      6th commit  ← HEAD
 *      5th commit
 *      4th commit
 *      3rd commit  ← --from=3rdcommit
 *      2nd commit
 *      1st commit
 *    Result: reads 3rd, 4th, 5th, 6th, 7th commits (upward to HEAD).
 *    Find the hash: git log --oneline
 *    ⚠️ Running without --write previews only. Use --from --write to apply:
 *    php generate_version_details.php --write --from=abc1234
 *    You can combine it with --v to set a specific version:
 *    php generate_version_details.php --from=abc1234 --v=3.0.0
 *
 * 5. php generate_version_details.php --from=abc1234 --to=def5678
 *    Reads commits in the range FROM → TO (both inclusive).
 *    Useful when you want to scope a version to a specific commit window.
 *    Example:
 *      7th commit
 *      6th commit  ← --to=6thcommit
 *      5th commit
 *      4th commit
 *      3rd commit  ← --from=3rdcommit
 *      2nd commit
 *      1st commit
 *    Result: reads 3rd, 4th, 5th, 6th commits only.
 *    Combine with --v to set the version:
 *    php generate_version_details.php --from=abc1234 --to=def5678 --v=2.3.0
 *
 * 6. php generate_version_details.php --v=2.3.0
 *    Override the auto-detected version number.
 *    By default it bumps patch version (e.g. 2.2.2 → 2.2.3).
 *
 * 7. php generate_version_details.php --help
 *    Show this guide in the terminal.
 *
 * COMMIT CONVENTIONS — how messages get categorized
 * --------------------------------------------------
 *   feat: / feature: / new:              → New Features
 *   fix: / bugfix: / hotfix:            → Bug Fixes
 *   improvement: / enhancement: / perf:  → Improvements
 *   refactor: / docs: / style: / chore: → Improvements
 *   breaking: or "breaking change"       → Breaking Changes
 *   (no prefix)                           → Improvements
 *
 * WORKFLOW — End of sprint (recommended two-step)
 * -------------------------------------------------
 *   php generate_version_details.php --count=40          # STEP 1: Preview
 *   # Review preview.html in version-details/version-preview/
 *   php generate_version_details.php --write               # STEP 2: Write
 *
 * WORKFLOW — From a commit up to HEAD
 * ------------------------------------
 *   git log --oneline                              # Find the starting hash
 *   php generate_version_details.php --from=abc1234 --v=3.0.0
 *   php generate_version_details.php --write
 *
 * WORKFLOW — Specific commit range
 * ---------------------------------
 *   git log --oneline                              # Find from and to hashes
 *   php generate_version_details.php --from=abc1234 --to=def5678 --v=2.3.0
 *   php generate_version_details.php --write
 *
 * WORKFLOW — One-liner (when you're confident)
 * ---------------------------------------------
 *   php generate_version_details.php --write --v=2.3.0 --count=30
 *
 * =============================================================================
 */

// ——————————————————————————————————————————————————————
// Config
// ——————————————————————————————————————————————————————

$repoPath      = __DIR__ . DIRECTORY_SEPARATOR . '..';
$sourceFile    = $repoPath . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR .
                 'semantic-versioning' . DIRECTORY_SEPARATOR . 'version2.php';
$previewDir    = $repoPath . DIRECTORY_SEPARATOR . 'version-details' .
                 DIRECTORY_SEPARATOR . 'version-preview';
$previewFile   = $previewDir . DIRECTORY_SEPARATOR . 'preview.html';
$defaultCount  = 20;

// ——————————————————————————————————————————————————————
// CLI Arguments
// ——————————————————————————————————————————————————————

$opts = getopt('', ['write', 'v::', 'from::', 'to::', 'count::', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Generate Version Details

USAGE:
  php generate_version_details.php [options]

OPTIONS:
  --write             Apply the saved preview to version2.php
  --v=VERSION         Set version to any number (e.g. 2.2.3, 3.0.0)
  --from=HASH         Start reading from this commit going UP toward HEAD
  --to=HASH           Stop reading at this commit (used with --from)
  --count=N           Number of recent commits to read (default: 20)
  --help              Show this help message

EXAMPLES:
  # Last 40 commits up to HEAD:
  php generate_version_details.php --count=40

  # From a specific commit up to HEAD:
  php generate_version_details.php --from=abc1234

  # A specific commit range (from → to, both inclusive):
  php generate_version_details.php --from=abc1234 --to=def5678

  # Range with version override:
  php generate_version_details.php --from=abc1234 --to=def5678 --v=2.3.0

  # Apply last preview:
  php generate_version_details.php --write

COMMIT CONVENTIONS:
  feat: / feature:                               → New Features
  fix: / bugfix:                                  → Bug Fixes
  improvement / refactor / docs / chore:         → Improvements
  breaking:                                       → Breaking Changes

HELP;
    exit(0);
}

$writeMode = isset($opts['write']);
$version   = $opts['v']    ?? null;
$fromHash  = $opts['from'] ?? null;
$toHash    = $opts['to']   ?? null;
$count     = isset($opts['count']) ? (int) $opts['count'] : $defaultCount;

// ——————————————————————————————————————————————————————
// Action
// ——————————————————————————————————————————————————————

if ($writeMode) {
    writeFromPreview($sourceFile, $previewFile);
} else {
    generatePreview($repoPath, $sourceFile, $previewFile, $previewDir,
                    $version, $fromHash, $toHash, $count);
}

// ——————————————————————————————————————————————————————
// STEP 2: Write from saved preview
// ——————————————————————————————————————————————————————

function writeFromPreview(string $sourceFile, string $previewFile): void {
    if (!file_exists($previewFile)) {
        echo "❌  No preview found at:\n   $previewFile\n\n";
        echo "   Run without --write first to generate a preview:\n";
        echo "   php generate_version_details.php --count=40\n";
        echo "   php generate_version_details.php --from=abc1234\n";
        exit(1);
    }

    $preview  = file_get_contents($previewFile);
    $existing = file_exists($sourceFile) ? file_get_contents($sourceFile) : '';
    $backup   = $sourceFile . '.backup.' . date('Ymd_His');

    if ($existing) {
        file_put_contents($backup, $existing);
    }

    file_put_contents($sourceFile, $preview . "\n" . $existing);

    echo border(70, '=');
    echo "  ✅  APPLIED TO version2.php\n";
    echo border(70, '=');
    echo "   📄  version2.php    : $sourceFile\n";
    echo "   📋  Backup         : $backup\n";
    echo "   🗑  Preview used   : $previewFile\n";
    echo border(70, '=');
}

// ——————————————————————————————————————————————————————
// STEP 1: Generate preview
// ——————————————————————————————————————————————————————

function generatePreview(string $repoPath, string $sourceFile,
                         string $previewFile, string $previewDir,
                         ?string $version, ?string $fromHash,
                         ?string $toHash, int $count): void {

    $format  = "--format=%H%n%an%n%aI%n%s%n---BODY---%n%b%n---END---";
    $baseArgs = "--first-parent --author-date-order";

    if ($fromHash && $toHash) {
        // Range: from commit X up to commit Y (both inclusive)
        // git log <from>^..<to> gives commits after fromHash up to toHash.
        // Adding the ^ excludes fromHash's parent, making fromHash itself included.
        $range   = escapeshellarg("{$fromHash}^") . ".." . escapeshellarg($toHash);
        $gitArgs = "$baseArgs $range $format";
        $rangeLabel = "$fromHash → $toHash";
    } elseif ($fromHash) {
        // From commit X upward to HEAD (inclusive of fromHash)
        $range   = escapeshellarg("{$fromHash}^") . "..HEAD";
        $gitArgs = "$baseArgs $range $format";
        $rangeLabel = "$fromHash → HEAD";
    } else {
        // Plain count from HEAD going back
        $gitArgs    = "$baseArgs -n $count $format";
        $rangeLabel = "HEAD, last $count commits";
    }

    $raw     = runGitLog($repoPath, $gitArgs);
    $commits = parseCommits($raw);

    if (empty($commits)) {
        echo "❌  No commits found.\n";
        if ($fromHash) {
            echo "   Check that the hash exists: git log --oneline | grep $fromHash\n";
            if ($toHash) {
                echo "   Also check --to hash: git log --oneline | grep $toHash\n";
            }
        }
        exit(1);
    }

    if ($version === null) {
        $version = detectAndBumpVersion($sourceFile);
        echo "📦  Detected: next version will be $version\n";
    } else {
        echo "📦  Version: $version\n";
    }

    $versionId   = str_replace('.', '', $version);
    // Use the newest commit's date (commits are newest-first)
    $displayDate = formatDisplayDate($commits[0]['date'] ?? date('Y-m-d'));
    $changelog   = buildChangelog($commits);

    $hasFeatures     = !empty($changelog['feature']);
    $hasImprovements = !empty($changelog['improvement']);
    $hasFixes        = !empty($changelog['fix']);
    $hasBreaking     = !empty($changelog['breaking']);

    // Build HTML
    $entries = buildVersionHtml($version, $versionId, $displayDate,
                               $changelog, $hasFeatures,
                               $hasImprovements, $hasFixes, $hasBreaking);

    // Save preview file
    if (!is_dir($previewDir)) {
        mkdir($previewDir, 0755, true);
    }
    file_put_contents($previewFile, $entries);
    echo "📁  Preview saved: $previewFile\n\n";

    // Print full preview to terminal
    printPreview($version, $displayDate, $commits, $changelog,
                 $hasFeatures, $hasImprovements, $hasFixes, $hasBreaking,
                 $rangeLabel, $entries);
}

// ——————————————————————————————————————————————————————
// Build HTML
// ——————————————————————————————————————————————————————

function buildVersionHtml(string $version, string $versionId, string $displayDate,
                           array $changelog, bool $hasFeatures, bool $hasImprovements,
                           bool $hasFixes, bool $hasBreaking): string {
    $entries = [];
    $entries[] = "<!-- Version $version -->";
    $entries[] = "<div class=\"accordion-item\">";
    $entries[] = "    <h2 class=\"accordion-header\">";
    $entries[] = "    <button class=\"accordion-button\" type=\"button\" data-bs-toggle=\"collapse\" data-bs-target=\"#version$versionId\" aria-expanded=\"true\" aria-controls=\"version$versionId\">";
    $entries[] = "        <i class=\"fas fa-tag me-2 text-danger\"></i>";
    $entries[] = "        <strong>Version $version</strong>";
    $entries[] = "        <span class=\"badge bg-success ms-2\">Latest</span>";
    $entries[] = "    </button>";
    $entries[] = "    </h2>";
    $entries[] = "    <div id=\"version$versionId\" class=\"accordion-collapse collapse show\" data-bs-parent=\"#versionAccordion\">";
    $entries[] = "    <div class=\"accordion-body\">";
    $entries[] = "        <div class=\"d-flex justify-content-start align-items-center mb-3\">";
    $entries[] = "            <span class=\"text-muted me-2\">Updated: $displayDate</span>";
    $entries[] = "            <span class=\"badge bg-success\">Latest</span>";
    $entries[] = "        </div>";
    if ($hasFeatures)     $entries[] = renderSection('new features',      'fa-plus-circle',         'success', $changelog['feature']);
    if ($hasImprovements) $entries[] = renderSection('improvements',        'fa-wrench',              'warning', $changelog['improvement']);
    if ($hasBreaking)     $entries[] = renderSection('breaking changes',    'fa-exclamation-triangle','info',    $changelog['breaking']);
    if ($hasFixes)        $entries[] = renderSection('bug fixes',           'fa-bug',                'danger',  $changelog['fix']);
    $entries[] = "    </div>";
    $entries[] = "    </div>";
    $entries[] = "</div>";
    $entries[] = "";
    return implode("\n", $entries);
}

function renderSection(string $label, string $faIcon, string $color, array $items): string {
    if (empty($items)) return '';
    $html = "        <!-- " . ucfirst($label) . " -->\n";
    $html .= "        <h6 class=\"text-$color\"><i class=\"fas $faIcon me-1\"></i> " . ucfirst($label) . ":</h6>\n";
    $html .= "        <ul class=\"list-unstyled ps-3\">\n";
    foreach ($items as $item) {
        $item = htmlspecialchars(trim($item), ENT_QUOTES, 'UTF-8');
        $arrowIcon = match ($label) {
            'new features'    => 'fa-check',
            'improvements'    => 'fa-arrow-up',
            'bug fixes'       => 'fa-times',
            'breaking changes'=> 'fa-exclamation',
            default           => 'fa-circle',
        };
        $html .= "            <li class=\"mb-2\"><i class=\"fas $arrowIcon text-$color me-2\"></i>$item</li>\n";
    }
    $html .= "        </ul>\n";
    return $html;
}

// ——————————————————————————————————————————————————————
// Print to terminal
// ——————————————————————————————————————————————————————

function printPreview(string $version, string $displayDate, array $commits,
                       array $changelog, bool $hasFeatures, bool $hasImprovements,
                       bool $hasFixes, bool $hasBreaking,
                       string $rangeLabel, string $generated): void {

    echo border(70, '=');
    echo "  PREVIEW\n";
    echo border(70, '=');
    echo "\n";
    echo "📦  Version  : $version\n";
    echo "📅  Date     : $displayDate\n";
    echo "📂  Commits  : " . count($commits) . "\n";
    echo "🔀  Source   : $rangeLabel\n\n";

    echo border(70, '-');
    echo "  CHANGELOG SUMMARY\n";
    echo border(70, '-');
    if ($hasFeatures)     echo "  ✦ New Features     : " . count($changelog['feature'])     . " item(s)\n";
    if ($hasImprovements) echo "  ✦ Improvements     : " . count($changelog['improvement']) . " item(s)\n";
    if ($hasFixes)        echo "  ✦ Bug Fixes        : " . count($changelog['fix'])         . " item(s)\n";
    if ($hasBreaking)     echo "  ✦ Breaking Changes : " . count($changelog['breaking'])     . " item(s)\n";
    if (!$hasFeatures && !$hasImprovements && !$hasFixes && !$hasBreaking) {
        echo "  (No categorized commits found)\n";
    }
    echo "\n";

    echo border(70, '-');
    echo "  COMMIT LIST\n";
    echo border(70, '-');
    foreach ($commits as $idx => $c) {
        [$type, ] = categorizeCommit($c['subject'], $c['body'] ?? '');
        $tag = match ($type) {
            'feature'     => '📦',
            'fix'         => '🐛',
            'improvement' => '🔧',
            'breaking'    => '⚠️ ',
            default       => '• ',
        };
        $num  = sprintf("%2d", $idx + 1);
        $date = substr($c['date'], 0, 10);
        $msg  = substr($c['subject'], 0, 72) . (strlen($c['subject']) > 72 ? '…' : '');
        echo "  $num. $tag  [$date]  $msg\n";
    }
    echo "\n";

    echo border(70, '=');
    echo "  GENERATED HTML\n";
    echo border(70, '=');
    echo "\n$generated\n\n";

    echo border(70, '=');
    echo "  NEXT STEP\n";
    echo border(70, '=');
    echo "  To apply this to version2.php, run:\n";
    echo "  php generate_version_details.php --write\n\n";
    echo "  The preview is saved at:\n";
    echo "  version-details/version-preview/preview.html\n";
    echo border(70, '=');
}

// ——————————————————————————————————————————————————————
// Git helpers
// ——————————————————————————————————————————————————————

function runGitLog(string $repoPath, string $args): string {
    $cmd = "git -C " . escapeshellarg($repoPath) . " log $args 2>&1";
    return shell_exec($cmd) ?: '';
}

function parseCommits(string $raw): array {
    if (trim($raw) === '') return [];
    $chunks  = array_filter(array_map('trim', explode('---END---', $raw)), fn($s) => $s !== '');
    $commits = [];
    foreach ($chunks as $chunk) {
        $parts   = explode("---BODY---", $chunk);
        $header  = trim($parts[0] ?? '');
        $body    = trim($parts[1] ?? '');
        $lines   = explode("\n", $header);
        if (count($lines) < 4) continue;
        $hash    = trim($lines[0] ?? '');
        $author  = trim($lines[1] ?? '');
        $date    = trim($lines[2] ?? '');
        $subject = trim($lines[3] ?? '');
        if ($hash && $subject) {
            $commits[] = [
                'hash'    => substr($hash, 0, 7),
                'author'  => $author,
                'date'    => $date,
                'subject' => $subject,
                'body'    => $body,
            ];
        }
    }
    return $commits;
}

function categorizeCommit(string $msg, string $body = ''): array {
    $msgLower = strtolower($msg);
    $combo    = $msgLower . ' ' . strtolower($body);

    if (preg_match('/^breaking(?:\s|$|:)/', $msg) || strpos($combo, 'breaking change') !== false) {
        return ['breaking', cleanSubject($msg)];
    }
    if (preg_match('/^(feat|feature|new)\s*[:(-]?\s*/i', $msg)) {
        return ['feature', cleanSubject($msg)];
    }
    if (preg_match('/^(fix|bugfix|hotfix)\s*[:(-]?\s*/i', $msg)) {
        return ['fix', cleanSubject($msg)];
    }
    return ['improvement', cleanSubject($msg)];
}

function cleanSubject(string $msg): string {
    return trim(preg_replace(
        '/^(feat(?:ure)?|fix|perf|improvement|enhancement|refactor|docs?|style|test|chore|build|ci|breaking)\s*[:(-]?\s*/i',
        '', $msg
    ));
}

function buildChangelog(array $commits): array {
    $groups = ['feature' => [], 'improvement' => [], 'fix' => [], 'breaking' => []];
    foreach ($commits as $c) {
        $subject = $c['subject'];
        if (stripos($subject, 'Merge branch') === 0) continue;
        if (stripos($subject, 'Revert')       === 0) continue;
        [$type, $text] = categorizeCommit($subject, $c['body'] ?? '');
        if ($text) $groups[$type][] = $text;
    }
    foreach ($groups as &$items) {
        $items = array_values(array_unique($items));
    }
    unset($items);
    return $groups;
}

// ——————————————————————————————————————————————————————
// Utility
// ——————————————————————————————————————————————————————

function border(int $w, string $c): string { return str_repeat($c, $w) . "\n"; }

function detectAndBumpVersion(string $filePath): string {
    if (!file_exists($filePath)) return '2.2.3';
    $content = file_get_contents($filePath);
    if (preg_match_all('/<strong>Version\s+(\d+\.\d+\.\d+)/', $content, $m)) {
        $versions = $m[1];
        usort($versions, fn($a, $b) => version_compare($b, $a));
        if ($versions[0]) {
            $p    = explode('.', $versions[0]);
            $p[2] = (int)$p[2] + 1;
            return implode('.', $p);
        }
    }
    return '2.2.3';
}

function formatDisplayDate(string $gitDate): string {
    if (!$gitDate) return date('F j, Y');
    $dt = DateTime::createFromFormat('Y-m-d\TH:i:sP', trim($gitDate));
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d', trim($gitDate));
    return $dt ? $dt->format('F j, Y') : trim($gitDate);
}