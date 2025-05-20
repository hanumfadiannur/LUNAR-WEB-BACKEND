<?php
require 'vendor/autoload.php';

use Kreait\Firebase\Factory;

$firebaseConfigPath = __DIR__ . '/storage/app/firebase/firebase_credentials.json';

$factory = (new Factory)->withServiceAccount($firebaseConfigPath);
$db = $factory->createFirestore()->database();

try {
    $docRef = $db->collection('users')->document('testDoc');
    $docRef->set([
        'name' => 'Test User',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "Data saved to Firestore.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
