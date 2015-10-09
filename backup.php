<?php
require_once 'vendor/autoload.php';

use OpenCloud\Rackspace;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$localPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR;
$formatString = 'Y-m-d\_H:iP';
$now = (new DateTime('NOW', new DateTimeZone('America/New_York')));
$past = (new DateTime('NOW', new DateTimeZone('America/New_York')))->sub(new DateInterval('P1M'));
$extension = '.sql.gz';

$ignored = explode(',', getenv('DB_IGNORE'));
$dbh = new PDO("mysql:host=" . getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
$dbs = $dbh->query('SHOW DATABASES');

$rsClient = new Rackspace(Rackspace::US_IDENTITY_ENDPOINT, array(
    'username' => getenv('RS_USERNAME'),
    'apiKey'   => getenv('RS_API_KEY')
));

$rsService = $rsClient->objectStoreService('cloudFiles', getenv('RS_LOCATION'));
$rsContainer = $rsService->getContainer(getenv('RS_CONTAINER'));

while (($db = $dbs->fetchColumn(0)) !== false) {
    if (in_array($db, $ignored)) {
        continue;
    }

    echo "Exporting " . $db . " now...\n";

    // generate the dump file
    system("mysqldump -h " . getenv('DB_HOST') . " --lock-tables=false --user=" . getenv('DB_USERNAME') . " --password=" . getenv('DB_PASSWORD') . " --add-drop-database " . $db . " | gzip -c > " . $localPath . $db . $extension);

    // upload to RS
    $data = fopen($localPath . $db . $extension, 'r+');
    $rsContainer->uploadObject($db . '/' . $now->format($formatString) . $extension, $data);

    // delete local file
    unlink($localPath . $db . $extension);

    // remove the file from 30 days ago, except if it's the first one
    if ($now->format('H') != '00') {
        try {
            $rsContainer->getObject($db . '/' . $past->format($formatString) . $extension)->delete();
        } catch (Exception $e) {
            // we don't care if we try to delete an object that doesn't exist
        }
    }
}
