<?php

declare(strict_types=1);

/**
 * iHymns — Presentation themes / styling groundwork for casting & projection (#1168 / #1170)
 *
 * Copyright (c) 2026 iHymns. All rights reserved.
 *
 * PURPOSE:
 * iHymns separates CONTENT (lyrics/notes/chords in tblSongComponents / tblLyricLines)
 * from PRESENTATION. Today the importers (G-Presenter #1087, ProPresenter, OpenLP, …)
 * DROP per-slide styling (fonts, colours, backgrounds, alignment, effects, transitions,
 * layout). As iHymns moves toward casting/projection to big screens (#297 web present
 * mode; native #1104/#1116/#331/#332/#210/#1115; bilingual projection #1100), we store
 * presentation styling NOW so the groundwork is in place and round-trips are lossless.
 *
 * Per the one-pass forward-looking discipline (CLAUDE.md rule #20) this ships the FINAL
 * styling schema as ONE additive, idempotent, DORMANT batch (nothing reads it until the
 * casting/editor feature consumes it). The attribute union was catalogued across every
 * importable/exportable format (G-Presenter, ProPresenter 6 .pro6 + 7+, OpenSong,
 * VideoPsalm, FreeShow, OpenLP, EasyWorship, MediaShout, Proclaim, OpenLyrics) so no
 * styling is lost on round-trip. A 3-lens adversarial stress pass hardened it
 * (multi-tenancy, parent-songbook cascade, lyrics-version + per-word anchors,
 * wide-gamut colour, anchor-exclusivity enforcement).
 *
 * EIGHT tables, ZERO columns on existing tables, ZERO behaviour change:
 *   - tblPresentationThemes            reusable, org-scoped theme registry (org-NULL = built-in)
 *   - tblPresentationThemeVariants     per-DisplayRole (audience|stage|…) full style surface
 *   - tblPresentationBackgroundLayers  z-ordered solid/gradient/image/video/foreground layers
 *   - tblPresentationFooterOverlays    CCLI/copyright footer token-templates per variant
 *   - tblPresentationThemeAssignments  the discriminated cascade spine (org→…→component)
 *   - tblPresentationSlideOverrides    per-slide / per-line / per-word STYLE patch (ref-only)
 *   - tblPresentationThemeTags         browse-by-theme tags
 *   - tblPresentationFormatFidelity    lossless round-trip carrier for not-yet-first-class styling
 *
 * CONTENT↔PRESENTATION SEPARATION IS ABSOLUTE — no table here stores a lyric string;
 * per-slide/line/word overrides only REFERENCE tblSongComponents / tblLyricLines /
 * tblLyricWords (rule #21: per-line/word enrichment anchors on the normalized BIGINT
 * read path, never a fragile LinesJson index; a "slide" is a render-time pagination
 * unit, not 1:1 with a component — #1065).
 *
 * STRICTLY ADDITIVE + IDEMPOTENT (CREATE TABLE IF NOT EXISTS throughout).
 *
 * USAGE:
 *   CLI:  php appWeb/.sql/migrate-presentation-themes.php
 *   Web:  /manage/setup-database → "Presentation themes + cascade" button
 *
 * @requires PHP 8.1+ with mysqli
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli && !defined('IHYMNS_SETUP_DASHBOARD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
}

/* Survive shared-host limits even though these are quick CREATEs (belt-and-braces). */
@set_time_limit(0);
@ignore_user_abort(true);

function _migPresTheme_output(string $msg): void {
    global $isCli;
    echo $msg . ($isCli ? "\n" : "<br>\n");
    if (!$isCli) flush();
}

function _migPresTheme_tableExists(\mysqli $db, string $table): bool {
    $r = $db->query("SHOW TABLES LIKE '{$table}'");
    return $r && $r->num_rows > 0;
}

$credFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.auth' . DIRECTORY_SEPARATOR . 'db_credentials.php';
if (!file_exists($credFile)) {
    _migPresTheme_output("ERROR: MySQL credentials not found. Run install.php first.");
    return;
}
require_once $credFile;

_migPresTheme_output("");
_migPresTheme_output("=== iHymns — Presentation themes / casting groundwork (#1168 / #1170) ===");
_migPresTheme_output("");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysql = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    $mysql->set_charset(defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4');
} catch (\mysqli_sql_exception $e) {
    _migPresTheme_output("ERROR: MySQL connection failed: " . $e->getMessage());
    return;
}
_migPresTheme_output("Connected to MySQL: " . DB_NAME);

/**
 * Each table is created idempotently (CREATE TABLE IF NOT EXISTS) so the migration is
 * safe to re-run. The DDL below is BYTE-IDENTICAL to its appWeb/.sql/schema.sql mirror
 * (rule #19) so a fresh install equals a migrated one.
 */
try {

    /* ---- 1. Theme registry --------------------------------------------------------- */
    _migPresTheme_output("--- tblPresentationThemes ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationThemes (
            Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            OrgId       INT UNSIGNED NULL DEFAULT NULL COMMENT 'Owner org; NULL = built-in/system theme shared across orgs',
            Name        VARCHAR(100) NOT NULL,
            Description TEXT NULL DEFAULT NULL,
            IsBuiltIn   TINYINT(1) NOT NULL DEFAULT 0,
            SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
            CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_Org (OrgId, IsBuiltIn, SortOrder),
            CONSTRAINT fk_PresTheme_Org FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Reusable presentation/projection themes, org-scoped; org-NULL = built-in. Dormant until casting/editor consumes (#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationThemes ensured.");

    /* ---- 2. Per-display-role style surface ----------------------------------------- */
    _migPresTheme_output("--- tblPresentationThemeVariants ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationThemeVariants (
            Id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ThemeId         INT UNSIGNED NOT NULL,
            DisplayRole     VARCHAR(30) NOT NULL DEFAULT 'audience' COMMENT 'audience|stage|lower_third|mirror|print — VARCHAR not ENUM (rule #20)',
            FontFamily      VARCHAR(100) NULL DEFAULT NULL,
            FontSizeScale   DECIMAL(5,3) NOT NULL DEFAULT 1.000 COMMENT '1.000 = 100% baseline',
            FontWeight      VARCHAR(20) NULL DEFAULT NULL COMMENT 'normal|bold|light|heavy — VARCHAR (rule #20)',
            TextColour      VARCHAR(32) NULL DEFAULT NULL COMMENT '#RRGGBB(AA) or wide-gamut/Display-P3 float string (PP7)',
            TextAlign       VARCHAR(20) NULL DEFAULT NULL COMMENT 'left|center|right|justified|natural',
            TextVAlign      VARCHAR(20) NULL DEFAULT NULL COMMENT 'top|middle|bottom',
            Uppercase       TINYINT(1) NOT NULL DEFAULT 0,
            LineHeight      DECIMAL(5,3) NULL DEFAULT NULL,
            LetterSpacing   DECIMAL(6,3) NULL DEFAULT NULL,
            OutlineColour   VARCHAR(32) NULL DEFAULT NULL,
            OutlineWidth    DECIMAL(6,3) NULL DEFAULT NULL,
            OutlineStyle    VARCHAR(20) NULL DEFAULT NULL COMMENT 'solid|dash|dot — VARCHAR (rule #20)',
            ShadowStyle     VARCHAR(20) NULL DEFAULT NULL COMMENT 'drop|inner|glow — VARCHAR (rule #20)',
            ShadowColour    VARCHAR(32) NULL DEFAULT NULL,
            ShadowOffset    DECIMAL(6,3) NULL DEFAULT NULL,
            ShadowRadius    DECIMAL(6,3) NULL DEFAULT NULL,
            ShadowOpacity   DECIMAL(4,3) NULL DEFAULT NULL,
            TextBgColour    VARCHAR(32) NULL DEFAULT NULL,
            TransitionType  VARCHAR(50) NULL DEFAULT NULL COMMENT 'fade|push|wipe|dissolve|morph|custom — VARCHAR (rule #20)',
            TransitionDurationMs INT UNSIGNED NULL DEFAULT NULL,
            TransitionDirection  VARCHAR(20) NULL DEFAULT NULL,
            TextAnimationType    VARCHAR(50) NULL DEFAULT NULL COMMENT 'appear|typewriter|fly|… — VARCHAR (rule #20)',
            TextAnimationDelayMs INT UNSIGNED NULL DEFAULT NULL,
            TextAnimationStart   VARCHAR(20) NULL DEFAULT NULL COMMENT 'on_click|with_previous|after_previous|with_slide',
            LayoutMode      VARCHAR(40) NOT NULL DEFAULT 'standard' COMMENT 'standard|bilingual_side_by_side|bilingual_stacked|lower_third — VARCHAR (rule #20)',
            PrimaryLanguageCode VARCHAR(35) NULL DEFAULT NULL COMMENT 'IETF BCP 47; NULL = song lang (free-text, rule #21 — no FK)',
            AltLanguageCode     VARCHAR(35) NULL DEFAULT NULL,
            MaxLinesPerSlide INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = no limit (#1065)',
            ScaleMode       VARCHAR(30) NULL DEFAULT NULL COMMENT 'fit_container|scale_up|scale_down|scale_up_down',
            SafeAreaInsetJson JSON NULL DEFAULT NULL COMMENT '{top,bottom,left,right} points',
            HighlightMode   VARCHAR(20) NOT NULL DEFAULT 'line' COMMENT 'word|line|syllable — VARCHAR (rule #20); timing reads tblLyricWords/tblLyricSyllables',
            HighlightColour VARCHAR(32) NULL DEFAULT NULL,
            HighlightDurationMs INT UNSIGNED NULL DEFAULT NULL,
            HighlightFadeMs INT UNSIGNED NULL DEFAULT NULL,
            CreatedAt       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ThemeRole (ThemeId, DisplayRole),
            CONSTRAINT fk_PresVariant_Theme FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Per-display-role (audience vs stage) style surface of a theme: text/colour/align/layout/transition/karaoke/bilingual (#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationThemeVariants ensured.");

    /* ---- 3. Z-ordered background / foreground media layers -------------------------- */
    _migPresTheme_output("--- tblPresentationBackgroundLayers ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationBackgroundLayers (
            Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            VariantId   INT UNSIGNED NOT NULL,
            ZIndex      INT NOT NULL DEFAULT 0,
            BackgroundType VARCHAR(20) NOT NULL DEFAULT 'solid' COMMENT 'solid|gradient|image|video|blur|invert — VARCHAR (rule #20)',
            Colour      VARCHAR(32) NULL DEFAULT NULL,
            GradientType VARCHAR(20) NULL DEFAULT NULL COMMENT 'linear|radial|angle',
            GradientAngle FLOAT NULL DEFAULT NULL,
            GradientStopsJson JSON NULL DEFAULT NULL COMMENT '[{colour,position,blendPoint}]',
            MediaUrl    VARCHAR(1000) NULL DEFAULT NULL,
            MediaId     VARCHAR(200) NULL DEFAULT NULL COMMENT 'Non-portable host-library handle; round-trip only, never auto-fetched',
            Opacity     DECIMAL(4,3) NULL DEFAULT NULL,
            BlurAmount  FLOAT NULL DEFAULT NULL,
            LoopMs      INT UNSIGNED NULL DEFAULT NULL,
            IsForeground TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = foreground media layered OVER text',
            CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_VariantZ (VariantId, ZIndex),
            CONSTRAINT fk_PresBgLayer_Variant FOREIGN KEY (VariantId) REFERENCES tblPresentationThemeVariants(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Z-ordered background/foreground media layers per variant (#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationBackgroundLayers ensured.");

    /* ---- 4. CCLI / copyright footer overlays --------------------------------------- */
    _migPresTheme_output("--- tblPresentationFooterOverlays ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationFooterOverlays (
            Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            VariantId   INT UNSIGNED NOT NULL,
            Template    VARCHAR(500) NOT NULL COMMENT 'Token string, e.g. \"{ccli} © {copyright}\" — tokens resolved at render from tblSongs, NOT stored',
            Position    VARCHAR(30) NOT NULL DEFAULT 'bottom' COMMENT 'bottom|bottom_left|top_right|… — VARCHAR (rule #20)',
            FontFamily  VARCHAR(100) NULL DEFAULT NULL,
            FontSizeScale DECIMAL(5,3) NULL DEFAULT NULL,
            Colour      VARCHAR(32) NULL DEFAULT NULL,
            Opacity     DECIMAL(4,3) NULL DEFAULT NULL,
            SortOrder   INT UNSIGNED NOT NULL DEFAULT 0,
            CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_Variant (VariantId, SortOrder),
            CONSTRAINT fk_PresFooter_Variant FOREIGN KEY (VariantId) REFERENCES tblPresentationThemeVariants(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='CCLI/copyright/ref footer overlays per variant; >1 allowed (#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationFooterOverlays ensured.");

    /* ---- 5. The cascade spine ------------------------------------------------------ *
     * ONE discriminated table (NOT a ThemeId column smeared across 5 anchor tables, NOT
     * a polymorphic single FK): each anchor keeps its OWN typed, FK-constrained column
     * (rule #15). Tenancy: OrgId + UserId qualify whose override an entity-scoped row is
     * (both NULL = the global/built-in default for that target; OrgId set = an org's
     * override; UserId set = a worship-leader's personal override). Uniqueness is enforced
     * by the STORED generated AssignmentKey (never NULL — NULL-leak-proof, unlike a
     * multi-column UNIQUE over nullable anchors) + a CHECK binding each scope to exactly
     * its anchor (enforced on MySQL 8.0.16+, app-validated on 5.7). Cascade resolution at
     * cast time (DB-direct, rule #17): org_default → songbook (incl. parent songbooks via
     * tblSongbooks.ParentSongbookId #782, and ALL of a song's songbooks via
     * tblSongbookEntries #1044; SongbookId resolves tblSongs.SongbookAbbr→tblSongbooks
     * .Abbreviation→.Id) → song → arrangement → component; last-non-null wins per attribute. */
    _migPresTheme_output("--- tblPresentationThemeAssignments ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationThemeAssignments (
            Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ThemeId     INT UNSIGNED NOT NULL,
            AssignmentScope VARCHAR(20) NOT NULL COMMENT 'org_default|user_default|songbook|song|arrangement|component — VARCHAR (rule #20); a future setlist scope needs no ALTER',
            OrgId         INT UNSIGNED NULL DEFAULT NULL COMMENT 'Tenant: org_default anchor, or the org whose entity-override this is',
            UserId        INT UNSIGNED NULL DEFAULT NULL COMMENT 'Tenant: user_default anchor, or the user whose personal entity-override this is',
            SongbookId    INT UNSIGNED NULL DEFAULT NULL,
            SongId        VARCHAR(20)  NULL DEFAULT NULL,
            ArrangementId INT UNSIGNED NULL DEFAULT NULL,
            ComponentId   INT UNSIGNED NULL DEFAULT NULL,
            DisplayRole   VARCHAR(30)  NULL DEFAULT NULL COMMENT 'NULL = applies to all display roles; else binds only that variant',
            Priority      INT NOT NULL DEFAULT 0 COMMENT 'Tie-break within a scope level',
            CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_Theme (ThemeId),
            INDEX idx_Scope (AssignmentScope),
            INDEX idx_Org (OrgId),
            INDEX idx_Song (SongId),
            INDEX idx_Songbook (SongbookId),
            CONSTRAINT fk_PresAssign_Theme     FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresAssign_Org       FOREIGN KEY (OrgId) REFERENCES tblOrganisations(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresAssign_User      FOREIGN KEY (UserId) REFERENCES tblUsers(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresAssign_Songbook  FOREIGN KEY (SongbookId) REFERENCES tblSongbooks(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresAssign_Song      FOREIGN KEY (SongId) REFERENCES tblSongs(SongId) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresAssign_Arr       FOREIGN KEY (ArrangementId) REFERENCES tblSongArrangements(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresAssign_Component FOREIGN KEY (ComponentId) REFERENCES tblSongComponents(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Theme→scope cascade spine (org→songbook→song→arrangement→component) with org/user tenancy; per-anchor typed FKs. Uniqueness + anchor-exclusivity are APP-enforced — a STORED generated key / CHECK cannot coexist with the FK CASCADE actions we need for cleanup (MySQL errors 3823/1215) (#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationThemeAssignments ensured.");

    /* ---- 6. Per-slide / per-line / per-word STYLE override (reference-only) --------- *
     * STYLE ONLY — never a lyric string. ComponentId is the slide-host (a component
     * paginates to many physical slides at render, #1065, so this patches the component,
     * not a slide ordinal). LyricsId scopes the patch to one lyrics version (a song has
     * many — tblLyrics UNIQUE(SongId,Source)). LyricLineId / LyricWordId anchor per-LINE
     * and per-WORD tweaks (e.g. karaoke highlight) on the stable normalized read path
     * (rule #21), never a LinesJson index. */
    _migPresTheme_output("--- tblPresentationSlideOverrides ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationSlideOverrides (
            Id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ComponentId INT UNSIGNED NOT NULL COMMENT 'The component/slide-host (tblSongComponents.Id) this override patches',
            LyricsId    INT UNSIGNED NULL DEFAULT NULL COMMENT 'Scopes the patch to one lyrics version (tblLyrics.Id); NULL = version-agnostic',
            LyricLineId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional per-LINE anchor → tblLyricLines.Id (rule #21)',
            LyricWordId BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Optional per-WORD anchor → tblLyricWords.Id for per-word karaoke styling (rule #21)',
            LineIndex   INT UNSIGNED NULL DEFAULT NULL COMMENT 'Transitional fallback index into LinesJson when no tblLyricLines row exists yet',
            DisplayRole VARCHAR(30) NULL DEFAULT NULL COMMENT 'NULL = all roles',
            StylePatchJson JSON NOT NULL COMMENT 'Sparse style patch {fontSizeScale,textColour,slideBreakAfter,highlightColour,foregroundMediaUrl,…} — STYLE ONLY, never lyric text',
            CreatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_Component (ComponentId),
            INDEX idx_Line (LyricLineId),
            INDEX idx_Word (LyricWordId),
            CONSTRAINT fk_PresOverride_Component FOREIGN KEY (ComponentId) REFERENCES tblSongComponents(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresOverride_Lyrics    FOREIGN KEY (LyricsId) REFERENCES tblLyrics(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresOverride_Line      FOREIGN KEY (LyricLineId) REFERENCES tblLyricLines(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresOverride_Word      FOREIGN KEY (LyricWordId) REFERENCES tblLyricWords(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Per-slide / per-line / per-word STYLE patch (reference-only, no lyric text). Anchors on tblLyricLines.Id / tblLyricWords.Id (rule #21) (#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationSlideOverrides ensured.");

    /* ---- 7. Browse-by-theme tags --------------------------------------------------- */
    _migPresTheme_output("--- tblPresentationThemeTags ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationThemeTags (
            Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ThemeId   INT UNSIGNED NOT NULL,
            Tag       VARCHAR(60) NOT NULL,
            CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ThemeTag (ThemeId, Tag),
            CONSTRAINT fk_PresTag_Theme FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Browse-by-theme tags, e.g. Advent / Good Friday / dark (#1148/#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationThemeTags ensured.");

    /* ---- 8. Round-trip fidelity carrier -------------------------------------------- *
     * Lossless carry of source styling that has no first-class column yet (PP7 build
     * order, FreeShow item CSS, VideoPsalm theme blob). Can attach at theme, variant, or
     * per-slide (ComponentId) granularity — PP7 builds are per-slide. (SourceFormat,
     * SourceRef) UNIQUE makes re-import idempotent (rule #20). */
    _migPresTheme_output("--- tblPresentationFormatFidelity ---");
    $mysql->query(
        "CREATE TABLE IF NOT EXISTS tblPresentationFormatFidelity (
            Id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ThemeId   INT UNSIGNED NULL DEFAULT NULL,
            VariantId INT UNSIGNED NULL DEFAULT NULL,
            ComponentId INT UNSIGNED NULL DEFAULT NULL COMMENT 'Per-slide anchor (PP7 build orders are per-slide)',
            SourceFormat VARCHAR(40) NOT NULL COMMENT 'propresenter6|propresenter7|freeshow|videopsalm|openlp|gpresenter|easyworship|mediashout|proclaim — VARCHAR (rule #20)',
            SourceRef VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'Element UUID / item key in the source file',
            RawJson   JSON NOT NULL COMMENT 'Lossless carry of source styling with no first-class column yet',
            CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_Fidelity (ThemeId, VariantId, ComponentId, SourceFormat, SourceRef),
            INDEX idx_Component (ComponentId),
            CONSTRAINT fk_PresFidelity_Theme     FOREIGN KEY (ThemeId) REFERENCES tblPresentationThemes(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresFidelity_Variant   FOREIGN KEY (VariantId) REFERENCES tblPresentationThemeVariants(Id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_PresFidelity_Component FOREIGN KEY (ComponentId) REFERENCES tblSongComponents(Id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Round-trip fidelity carrier for source styling with no first-class column; (Source,SourceRef) UNIQUE = idempotent re-import (rule #20) (#1168).'"
    );
    _migPresTheme_output("  [OK] tblPresentationFormatFidelity ensured.");

    _migPresTheme_output("");
    _migPresTheme_output("--- Summary ---");
    _migPresTheme_output("  Presentation-styling groundwork is in place (8 dormant tables). Importers can now");
    _migPresTheme_output("  POPULATE styling (G-Presenter #1087 first) instead of dropping it; casting/projection");
    _migPresTheme_output("  (#297/#1104/#1116/#331/#332) will CONSUME the resolved theme. Nothing reads it yet.");
    _migPresTheme_output("");
    _migPresTheme_output("Migration complete.");
} catch (\Throwable $e) {
    _migPresTheme_output("  [ERROR] " . $e->getMessage());
}

$mysql->close();
return;
