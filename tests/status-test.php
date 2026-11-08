<?php
/* The Status tab's checks against the kit's made-up TL-D1600S server and variations of it. Run: php tests/status-test.php <work dir> */
$W = $argv[1];
$P = __DIR__ . '/../src/usr/local/emhttp/plugins/qnap-expansion';
$fail = 0;
$is = function (string $what, $got, $want) use (&$fail) {
  if ($got === $want) echo "ok   $what\n"; else { echo "FAIL $what: got " . json_encode($got) . ', want ' . json_encode($want) . "\n"; $fail = 1; }
};
$mk = function (string $d, array $links = []) {
  exec('rm -rf ' . escapeshellarg($d)); mkdir($d, 0755, true);
  $f = function () use ($d, $links) { $ENCLOSURE_FIXTURE = $d; $ENCLOSURE_FIXTURE_LINKS = $links; require __DIR__ . '/../kit/render/enclosure-fixture.php'; require __DIR__ . '/../kit/render/enclosure-state-fixture.php'; };
  $f();
};
require "$P/include/qxp-status.php";
$states = function ($s) { $o = []; foreach ($s['checks'] as $c) $o[$c[0]] = $c[1]; return $o; };
$mk("$W/a"); putenv("DRIVEMAP_ROOT=$W/a");
$s = qxp_collect();
$is('measured unit: everything fine but the cabling', [$s['verdict'][0], $states($s)], ['warn', ['Card' => 'ok', 'Driver' => 'ok', 'PCIe links' => 'ok', 'PCIe errors' => 'ok', 'Drives' => 'ok', 'Cabling' => 'warn', 'Fans, LEDs, firmware' => 'info']]);
@mkdir("$W/a/boot/config/plugins/qnap-expansion", 0755, true);
file_put_contents("$W/a/boot/config/plugins/qnap-expansion/enclosures.json", '{"0000:01:00.0":[3,4,1,2]}');
$is('cabling confirmed: all fine', qxp_collect()['verdict'], ['ok', 'The enclosure works: card, driver, links and drives are fine.']);
$mk("$W/b", ['/0000:01:00.0' => ['5.0', 4, '8.0', 8]]); putenv("DRIVEMAP_ROOT=$W/b");
unlink("$W/b/sys/bus/pci/devices/0000:04:00.0/driver");
file_put_contents("$W/b/sys/bus/pci/devices/0000:03:00.0/aer_dev_correctable", "TOTAL_ERR_COR 12\n");
$s = qxp_collect(); $st = $states($s);
$is('no driver, a trained-down card and AER errors are found', [$s['verdict'][0], $st['Driver'], $st['PCIe links'], $st['PCIe errors']], ['fail', 'fail', 'warn', 'warn']);
$is('the details name them', [strpos($s['checks'][1][2], '0000:04:00.0 (no driver)') !== false, strpos($s['checks'][2][2], 'Card to slot: 5 GT/s x4 of 8 GT/s x8') !== false, strpos($s['checks'][3][2], '0000:03:00.0 correctable 12') !== false], [true, true, true]);
exec('rm -rf ' . escapeshellarg("$W/c")); mkdir("$W/c/sys/bus/pci/devices", 0755, true); putenv("DRIVEMAP_ROOT=$W/c");
$s = qxp_collect();
$is('no card: says so', [$s['verdict'][0], $s['checks'][0][0], strpos($s['checks'][0][2], 'QXP-1600eS-A1164 for the QNAP TL-D1600S') !== false], ['fail', 'Enclosure card', true]);
$html = qxp_render(qxp_collect());
$is('render escapes and draws the checks', [strpos($html, 'No enclosure card found.') !== false, strpos($html, '<script') === false], [true, true]);
exit($fail);
