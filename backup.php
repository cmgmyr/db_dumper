<?php

use BackblazeB2\Client;

require_once 'vendor/autoload.php';

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$client = new Client(getenv('B2_ACCOUNT_ID'), getenv('B2_MASTER_KEY'));

$localPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR;
$formatString = 'Y-m-d\_H:iP';
$now = (new DateTime('NOW', new DateTimeZone('America/New_York')));
$past = (new DateTime('NOW', new DateTimeZone('America/New_York')))->sub(new DateInterval('P1M'));
$extension = '.sql.gz';

$ignored = explode(',', getenv('DB_IGNORE'));
$dbh = new PDO('mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$dbs = $dbh->query('SHOW DATABASES');

while (($db = $dbs->fetchColumn(0)) !== false) {
    if (in_array($db, $ignored)) {
        continue;
    }

    echo 'Exporting ' . $db . " now...\n";

    // generate the dump file
    system('mysqldump -h ' . getenv('DB_HOST') . ' --port=' . getenv('DB_PORT') . ' --single-transaction --user=' . getenv('DB_USERNAME') . ' --password=' . getenv('DB_PASSWORD') . ' --add-drop-database  --set-gtid-purged=OFF ' . $db . ' | gzip -c > ' . $localPath . $db . $extension);

    // upload to B2
    $data = fopen($localPath . $db . $extension, 'r+');
    $client->upload([
        'BucketName' => getenv('B2_BUCKET'),
        'FileName' => $db . '/' . $now->format($formatString) . $extension,
        'Body' => $data,
    ]);

    // delete local file
    unlink($localPath . $db . $extension);

    // remove the file from 30 days ago, except if it's the first one
    if ($now->format('H') != '00') {
        try {
            $client->deleteFile([
                'BucketName' => getenv('B2_BUCKET'),
                'FileName' => $db . '/' . $past->format($formatString) . $extension,
            ]);
        } catch (Exception $e) {
            // we don't care if we try to delete an object that doesn't exist
        }
    }
}
