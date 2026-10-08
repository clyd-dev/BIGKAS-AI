/**
 * BIGKAS-AI metadata sheet helper — split oversized-audio rows (a/b halves)
 * ==========================================================================
 * PASTE THIS into your Google Sheet via Extensions > Apps Script, then Run.
 *
 * WHAT IT DOES (function splitRowsForHalves):
 *   For each of the 22 oversized files, finds its row by file_name
 *   (e.g. data/std010_g4_fil_chunk_16.wav), then:
 *     1. Renames that row's file_name to the "a" half
 *        (data/std010_g4_fil_chunk_16a.wav)
 *     2. Inserts a new row directly below, copied from the original
 *        (formatting + values), with file_name set to the "b" half
 *     3. Rewrites click_to_play in BOTH rows to a PLAY AUDIO hyperlink
 *        pointing at the matching file in your gdrive audio folder
 *   prompt_text / annotated_transcript are COPIED as-is into both rows —
 *   YOU divide the transcripts between halves by listening (the script
 *   cannot do that part).
 *
 * PREREQUISITES (do these first, in this order):
 *   1. Upload the 44 halves (audio_files/to_upload/) to gdrive audio_chunks.
 *   2. Remove the 22 oversized originals + 31 std004 orphans from Drive
 *      (or move them into a _oversized_originals/ folder in Drive).
 *   3. Paste YOUR audio_chunks folder ID into AUDIO_FOLDER_ID below.
 *      (Open the folder in Drive > the ID is the last part of the URL:
 *       drive.google.com/drive/folders/THIS_PART)
 *   4. Check SHEET_NAME matches your metadata tab name.
 *
 * OTHER FUNCTIONS:
 *   refreshAllAudioLinks() — rebuilds click_to_play for EVERY row from
 *     Drive (use after renaming files in Drive, e.g. the .WAV fixes).
 *   undoSplitRows() — reverses splitRowsForHalves (deletes "b" rows,
 *     renames "a" rows back to the original name, restores its link).
 */

// ============================ CONFIG ======================================
const SHEET_NAME = 'metadata';            // <-- your metadata tab name
const AUDIO_FOLDER_ID = 'PASTE_ID_HERE';  // <-- your audio_chunks folder ID
const FILE_PREFIX = 'data/';             // prefix used in file_name column
const LINK_LABEL = '▶ PLAY AUDIO';

// 22 oversized originals (basenames, from split_log.csv). The script
// derives the a/b names automatically: <stem>a.wav / <stem>b.wav
const SPLIT_ORIGINALS = [
  'std010_g4_fil_chunk_16.wav',
  'std010_g4_fil_chunk_17.wav',
  'std013_g4_fil_chunk_09.wav',
  'std013_g4_fil_chunk_10.wav',
  'std013_g4_fil_chunk_13.wav',
  'std014_g4_fil_chunk_10.wav',
  'std015_g4_fil_chunk_04.wav',
  'std021_g4_en_chunk_01.wav',
  'std021_g4_en_chunk_02.wav',
  'std021_g4_en_chunk_04.wav',
  'std021_g4_en_chunk_05.wav',
  'std022_g4_en_chunk_01.wav',
  'std022_g4_en_chunk_02.wav',
  'std023_g4_en_chunk_01.wav',
  'std023_g4_en_chunk_02.wav',
  'std028_g4_en_chunk_01.wav',
  'std028_g4_en_chunk_02.wav',
  'std032_g4_en_chunk_01.wav',
  'std032_g4_en_chunk_02.wav',
  'std040_g4_fil_chunk_04.wav',
  'std040_g4_fil_chunk_05.wav',
  'std041_g4_fil_chunk_15.wav',
];

// ============================ HELPERS =====================================

function getSheet_() {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET_NAME);
  if (!sheet) throw new Error('Sheet tab "' + SHEET_NAME + '" not found.');
  return sheet;
}

function getColIndexes_(sheet) {
  const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
  const idx = {};
  headers.forEach((h, i) => { idx[String(h).trim()] = i + 1; }); // 1-based
  if (!idx['file_name'] || !idx['click_to_play']) {
    throw new Error('Columns "file_name" and/or "click_to_play" not found in header row.');
  }
  return idx;
}

/** Map of Drive basename -> file URL for every file in the audio folder. */
function buildDriveUrlMap_() {
  const folder = DriveApp.getFolderById(AUDIO_FOLDER_ID);
  const map = {};
  const files = folder.getFiles();
  let n = 0;
  while (files.hasNext()) {
    const f = files.next();
    map[f.getName()] = f.getUrl();
    n++;
  }
  Logger.log('Drive folder contains ' + n + ' files.');
  return map;
}

function linkFormula_(url) {
  return '=HYPERLINK("' + url + '","' + LINK_LABEL + '")';
}

// ============================ MAIN ========================================

/**
 * Run this AFTER uploading the 44 halves to Drive.
 * Inserts the "b" rows, renames originals to "a", fixes both PLAY links.
 * Safe to re-run: already-converted pairs are skipped.
 */
function splitRowsForHalves() {
  const sheet = getSheet_();
  const col = getColIndexes_(sheet);
  const urlMap = buildDriveUrlMap_();

  const lastRow = sheet.getLastRow();
  const fileVals = sheet.getRange(2, col['file_name'], lastRow - 1, 1).getValues();
  const rowOf = {}; // full file_name value -> sheet row number
  fileVals.forEach((r, i) => { rowOf[String(r[0]).trim()] = i + 2; });

  // Collect work first, then process BOTTOM-UP so inserts don't shift targets.
  const jobs = [];
  SPLIT_ORIGINALS.forEach((base) => {
    const stem = base.replace(/\.wav$/i, '');
    const origKey = FILE_PREFIX + base;
    const aKey = FILE_PREFIX + stem + 'a.wav';
    const bKey = FILE_PREFIX + stem + 'b.wav';
    const origRow = rowOf[origKey] || null;
    const aRow = rowOf[aKey] || null;
    const bRow = rowOf[bKey] || null;
    jobs.push({ base, stem, origRow, aRow, bRow, aKey, bKey });
  });
  jobs.sort((x, y) => ((y.origRow || y.aRow || 0) - (x.origRow || x.aRow || 0)));

  let converted = 0, skipped = 0;
  const touchedRows = [];

  jobs.forEach((j) => {
    // Already fully converted -> skip (idempotent re-runs).
    if (j.aRow && j.bRow) { skipped++; return; }

    let anchorRow;
    if (j.origRow) {
      // Normal case: rename original row to the "a" half.
      sheet.getRange(j.origRow, col['file_name']).setValue(j.aKey);
      anchorRow = j.origRow;
    } else if (j.aRow && !j.bRow) {
      // Half-done re-run: "a" exists, only the "b" row is missing.
      anchorRow = j.aRow;
    } else {
      Logger.log('⚠️ NOT FOUND, skipping: ' + j.base);
      return;
    }

    // Insert new row below anchor, copy anchor row (values + formatting).
    sheet.insertRowsAfter(anchorRow, 1);
    const width = sheet.getLastColumn();
    sheet.getRange(anchorRow, 1, 1, width)
         .copyTo(sheet.getRange(anchorRow + 1, 1, 1, width),
                 SpreadsheetApp.CopyPasteType.PASTE_NORMAL, false);

    // Set the "b" file_name on the new row.
    sheet.getRange(anchorRow + 1, col['file_name']).setValue(j.bKey);

    // Fix PLAY links on both rows from Drive.
    const aUrl = urlMap[j.stem + 'a.wav'];
    const bUrl = urlMap[j.stem + 'b.wav'];
    if (aUrl) {
      sheet.getRange(anchorRow, col['click_to_play']).setFormula(linkFormula_(aUrl));
    } else {
      Logger.log('⚠️ Drive file missing (link not updated): ' + j.stem + 'a.wav');
    }
    if (bUrl) {
      sheet.getRange(anchorRow + 1, col['click_to_play']).setFormula(linkFormula_(bUrl));
    } else {
      Logger.log('⚠️ Drive file missing (link not updated): ' + j.stem + 'b.wav');
    }

    touchedRows.push(anchorRow, anchorRow + 1);
    converted++;
  });

  Logger.log('Done. Converted: ' + converted + ', already-done (skipped): ' + skipped);
  Logger.log('Divide prompt_text + annotated_transcript between halves in rows: '
             + touchedRows.sort((a, b) => a - b).join(', '));
}

/**
 * Rebuilds click_to_play for EVERY row from Drive (exact basename match
 * against file_name minus the "data/" prefix). Run after any Drive-side
 * rename (e.g. upper-to-lowercase .WAV fixes). Rows with no Drive match
 * are left untouched and logged.
 */
function refreshAllAudioLinks() {
  const sheet = getSheet_();
  const col = getColIndexes_(sheet);
  const urlMap = buildDriveUrlMap_();

  const lastRow = sheet.getLastRow();
  const fileVals = sheet.getRange(2, col['file_name'], lastRow - 1, 1).getValues();
  let updated = 0;
  const missing = [];
  fileVals.forEach((r, i) => {
    const full = String(r[0]).trim();
    const base = full.startsWith(FILE_PREFIX) ? full.slice(FILE_PREFIX.length) : full;
    const url = urlMap[base];
    if (url) {
      sheet.getRange(i + 2, col['click_to_play']).setFormula(linkFormula_(url));
      updated++;
    } else {
      missing.push(full);
    }
  });
  Logger.log('Links updated: ' + updated + ', no Drive match: ' + missing.length);
  missing.forEach((m) => Logger.log('  no match: ' + m));
}

/**
 * UNDO for splitRowsForHalves: deletes each "b" row, renames the "a" row
 * back to the original name, restores its PLAY link from Drive.
 * Processed bottom-up; safe to run once (second run finds nothing to do).
 */
function undoSplitRows() {
  const sheet = getSheet_();
  const col = getColIndexes_(sheet);
  const urlMap = buildDriveUrlMap_();

  const lastRow = sheet.getLastRow();
  const fileVals = sheet.getRange(2, col['file_name'], lastRow - 1, 1).getValues();
  const rowOf = {};
  fileVals.forEach((r, i) => { rowOf[String(r[0]).trim()] = i + 2; });

  const jobs = [];
  SPLIT_ORIGINALS.forEach((base) => {
    const stem = base.replace(/\.wav$/i, '');
    jobs.push({
      base,
      aRow: rowOf[FILE_PREFIX + stem + 'a.wav'] || null,
      bRow: rowOf[FILE_PREFIX + stem + 'b.wav'] || null,
    });
  });
  jobs.sort((x, y) => ((y.bRow || y.aRow || 0) - (x.bRow || x.aRow || 0)));

  let undone = 0;
  jobs.forEach((j) => {
    if (!j.aRow && !j.bRow) return; // nothing to undo
    if (j.bRow) sheet.deleteRow(j.bRow);
    // Recompute a-row (deletions above shift it): search again by name.
    const vals = sheet.getRange(2, col['file_name'], sheet.getLastRow() - 1, 1).getValues();
    let aRow = null;
    const stem = j.base.replace(/\.wav$/i, '');
    vals.forEach((r, i) => {
      if (String(r[0]).trim() === FILE_PREFIX + stem + 'a.wav') aRow = i + 2;
    });
    if (aRow) {
      sheet.getRange(aRow, col['file_name']).setValue(FILE_PREFIX + j.base);
      const url = urlMap[j.base];
      if (url) sheet.getRange(aRow, col['click_to_play']).setFormula(linkFormula_(url));
    }
    undone++;
  });
  Logger.log('Undone pairs: ' + undone);
}
