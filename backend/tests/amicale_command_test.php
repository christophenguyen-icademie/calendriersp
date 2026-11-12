<?php

// Integration tests reserved for the disposable compose.test.yml database.
require dirname(__DIR__).'/config/bootstrap.php';

use App\Entity\{Amicale, Campagne, User};
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
if ($em->getConnection()->getDatabase() !== 'calendriersp_test') {
    throw new RuntimeException('Test réservé à la base calendriersp_test.');
}
$app = new Application($kernel);
$command = $app->find('app:create-admin');
$check = static function (bool $valid, string $message): void {
    if (!$valid) { throw new RuntimeException($message); }
};
$run = static function (array $options, array $inputs = [], bool $interactive = true) use ($command): CommandTester {
    $tester = new CommandTester($command);
    $tester->setInputs($inputs);
    $tester->execute($options, ['interactive' => $interactive]);
    return $tester;
};
$password = 'Command-test-password-123!';
$long = 'command'.bin2hex(random_bytes(6)).'x'; // Exactly 20 characters.
do { $short = 'a'.bin2hex(random_bytes(2)); $short = substr($short, 0, 4); }
while ($em->getRepository(Amicale::class)->findOneBy(['identifiant' => $short]));

$before = $em->getRepository(Amicale::class)->count([]);
foreach (['abc', str_repeat('a', 21), 'avec-tiret', 'équipe'] as $invalid) {
    $result = $run(['--identifiant' => $invalid]);
    $check($result->getStatusCode() === Command::INVALID, 'Invalid command identifier accepted.');
}
$result = $run(['--identifiant' => $short], [], false);
$check($result->getStatusCode() === Command::FAILURE, 'Non-interactive creation accepted.');
$check($em->getRepository(Amicale::class)->count([]) === $before, 'Invalid creation left data.');

$result = $run(['--identifiant' => strtoupper($long)], ['Commande option', '10', 'command@example.test', $password]);
$check($result->getStatusCode() === Command::SUCCESS, 'Creation using option failed.');
$check(str_contains($result->getDisplay(), $long), 'Chosen identifier absent from success output.');
$check(!str_contains($result->getDisplay(), $password), 'Password exposed in command output.');
$amicale = $em->getRepository(Amicale::class)->findOneBy(['identifiant' => $long]);
$check($amicale !== null && $amicale->getNom() === 'Commande option', 'Identifier not persisted.');
$user = $em->getRepository(User::class)->findOneBy(['amicale' => $amicale]);
$check($user !== null && password_verify($password, $user->getPassword()), 'Administrator password not hashed correctly.');
$check(in_array('ROLE_ADMIN_AMICALE', $user->getRoles(), true), 'Administrator role missing.');
$check($em->getRepository(Campagne::class)->count(['amicale' => $amicale]) === 1, 'Default campaign missing.');
$check(!str_contains($result->getDisplay(), $amicale->getId()->toRfc4122()), 'Internal UUID shown as login identifier.');

$duplicate = $run(['--identifiant' => strtoupper($long)]);
$check($duplicate->getStatusCode() === Command::INVALID && str_contains($duplicate->getDisplay(), 'déjà utilisé'), 'Duplicate option not rejected clearly.');
$result = $run([], ['Commande interactive', strtoupper($long), 'abc', strtoupper($short), '10', 'command@example.test', $password]);
$check($result->getStatusCode() === Command::SUCCESS, 'Interactive identifier retry failed.');
$check($em->getRepository(Amicale::class)->findOneBy(['identifiant' => $short]) !== null, 'Four-character interactive identifier missing.');
$check($em->getRepository(Amicale::class)->count([]) === $before + 2, 'Unexpected number of amicales created.');
$check(!str_contains($result->getDisplay(), $password), 'Interactive password exposed.');
echo "OK: chosen 4–20 character identifiers, option and interactive retry, case normalization, uniqueness, hidden password and persisted administrator/campaign.\n";
$kernel->shutdown();
