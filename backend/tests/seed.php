<?php

// Used only by compose.test.yml, whose database is isolated and ephemeral.
require dirname(__DIR__).'/config/bootstrap.php';
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
if ($em->getConnection()->getDatabase() !== 'calendriersp_test') {
    throw new \RuntimeException('Les comptes de test sont réservés à la base calendriersp_test.');
}
$result = [];
foreach (['A', 'B'] as $name) {
    $amicale = new App\Entity\Amicale('Amicale de test '.$name, '75', 'test'.strtolower($name).bin2hex(random_bytes(5)));
    $user = new App\Entity\User($amicale, 'admin@example.test');
    $user->setRoles(['ROLE_ADMIN_AMICALE']);
    $user->setPassword(password_hash('Test-only-password-123!', PASSWORD_BCRYPT));
    $em->persist($amicale);
    $em->persist(new App\Entity\Campagne($amicale, (int) date('Y')));
    $em->persist($user);
    $result[$name] = ['amicaleId' => $amicale->getIdentifiant(), 'email' => $user->getEmail(), 'password' => 'Test-only-password-123!'];
}
$em->flush();
echo json_encode($result, JSON_THROW_ON_ERROR);
