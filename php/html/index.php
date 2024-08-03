<?php
include '../secure/query-commissions.php';

$shopOwnerId = $_POST["owner_id"] ?? null;

if ($shopOwnerId === null) {
  $owners = getShopOwners();

  echo <<<EOF
        <form method="POST">
            <label for="owner_id">Select Shop Owner:</label>
            <select name="owner_id" id="owner_id">
EOF;

  foreach ($owners as $id) {
    echo "<option value=\"$id\">$id</option>";
  }

  echo <<<EOF
            </select>
            <button type="submit">Submit</button>
        </form>
    EOF;

  echo "Please select a shop owner whose data to export.";
  exit(0);
}

$commissions = getCommissions($shopOwnerId);

downloadShopOwnerReportXlsx($shopOwnerId, $commissions);

/**
 * Generates and downloads an XLSX report for a shop owner.
 */
function downloadShopOwnerReportXlsx(string $shopOwnerId, Generator $commissions): void
{
  $timestamp = date('Y-m-d_H-i-s');
  $baseDir = __DIR__ . '/' . $timestamp;
  $csvFolder = $baseDir . "/csv";
  $xlsxFolder = $baseDir . "/xlsx";

  if (!file_exists($baseDir)) {
    mkdir($baseDir, 0777, true);
  }

  try {
    $shopCsvFiles = createShopsCsvFiles($csvFolder, $commissions);

    $csvSheetNames = $shopCsvFiles['sheets'];
    $csvFiles = $shopCsvFiles['files'];
  } catch (Exception $err) {
    echo $err;
    teardownExportFolders($timestamp);
    exit(1);
  }

  if (count($csvFiles) === 0) {
    echo "There are no shops matching the given shop owner.";
    exit(1);
  }

  if (!file_exists($xlsxFolder)) {
    mkdir($xlsxFolder, 0777, true);
  }

  try {
    $outputXlsx = $xlsxFolder . '/shops-export.xlsx';
    csvToXlsx($outputXlsx, $csvSheetNames, $csvFiles);
  } catch (Exception $err) {
    echo $err;
    teardownExportFolders($timestamp);
    exit(1);
  }

  // Remove CSV folder
  exec("rm -rf " . escapeshellarg($csvFolder), $execOutput, $return_var);

  // Compress xlsx
  $tarFileName = $baseDir . '/data-export.tar.gz';

  try {
    compressFile($tarFileName, $xlsxFolder);
  } catch (Exception $err) {
    echo $err;
    teardownExportFolders($timestamp);
    exit(1);
  }

  if (file_exists($tarFileName)) {
    ob_start(); // Start output buffering
    header('Content-Description: File Transfer');
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($tarFileName) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Content-Length: ' . filesize($tarFileName));
    readfile($tarFileName);
    ob_end_flush(); // Flush and end output buffering

    teardownExportFolders($timestamp);
    exit(0);
  } else {
    teardownExportFolders($timestamp);
    throw new Exception("Unable to export shops data for the ID: $shopOwnerId, tar not found");
    exit(1);
  }
}

/**
 * Formats sheet names for CSV to XLSX conversion.
 *
 * @param string $name The original sheet name.
 * @return string The formatted sheet name.
 */
function formatSheetNames(string $name): string
{
  $escapedName = escapeshellarg(preg_replace('/[^a-zA-Z0-9_ ]/u', ' ', $name));
  return " -s $escapedName ";
}

/**
 * Cleans up temporary export folders.
 *
 * @param string $timestamp The timestamp used for folder naming.
 * @throws Exception if the cleanup fails.
 */
function teardownExportFolders(string $timestamp): void
{
  exec("rm -rf " . escapeshellarg($timestamp), $execOutput, $return_var);
  if ($return_var != 0) {
    throw new Exception("Unable to clean-up tmp folder: $timestamp");
  }
}

/**
 * Creates CSV files for shops' commissions data.
 *
 * @throws Exception if a file cannot be written.
 */
function createShopsCsvFiles(string $csvFolder, Generator $commissions_data): array
{
  if (!file_exists($csvFolder)) {
    mkdir($csvFolder, 0777, true);
  }

  $csvFiles = [];
  $csvSheetNames = [];

  foreach ($commissions_data as $data) {
    $shop_name = $data['shop_name'];
    $shop_commissions = $data['commissions'];

    $csvFilePath = $csvFolder . '/' . $shop_name . '-data.csv';
    $csvSheetNames[] = $shop_name;
    $csvFiles[] = $csvFilePath;

    $output = fopen($csvFilePath, 'w');
    if ($output === false) {
      throw new Exception("Failed to open file for writing: $csvFilePath");
    }

    foreach ($shop_commissions as $commission) {
      fputcsv($output, $commission);
    }

    fclose($output);
  }

  return [
    "files" => $csvFiles,
    "sheets" => $csvSheetNames
  ];
}

/**
 * Converts CSV files to an XLSX file.
 *
 * @param string[] $csvSheetNames The names of the sheets in the XLSX file.
 * @param string[] $csvFiles The paths to the CSV files.
 * @throws Exception if the conversion fails.
 */
function csvToXlsx(string $outputPath, array $csvSheetNames, array $csvFiles): void
{
  $csv2xlsxCommand = 'csv2xlsx ' .
    implode(' ', array_map('formatSheetNames', $csvSheetNames)) .
    '--output ' . escapeshellarg($outputPath) .
    ' ' . implode(' ', array_map('escapeshellarg', $csvFiles));

  exec($csv2xlsxCommand, $execOutput, $return_var);

  if ($return_var !== 0) {
    throw new Exception("Error converting CSV to XLSX.<br>Command: $csv2xlsxCommand <br> Command output: " . implode("<br>", $execOutput));
  }
}

/**
 * Compresses a directory into a tar.gz file.
 *
 * @throws Exception if the compression fails.
 */
function compressFile(string $outputPath, string $srcDir): void
{
  exec(
    "tar -zcvf " . escapeshellarg($outputPath) . " -C " . escapeshellarg($srcDir) . " .",
    $execOutput,
    $return_var
  );

  if ($return_var !== 0) {
    throw new Exception("Unable to compress the given file.\noutput: $outputPath\ninput: $srcDir\n\n" . implode("\n", $execOutput));
  }
}
