<?php

use OpenCloud\Rackspace;

require_once 'vendor/autoload.php';

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$localPath = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR;
$timestamp = (new DateTime('NOW', new DateTimeZone('America/New_York')))->format('Y-m-d\_H:i:sP');
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
    $rsContainer->uploadObject($db . '/' . $timestamp . $extension, $data);

    // delete local file
    unlink($localPath . $db . $extension);
}
