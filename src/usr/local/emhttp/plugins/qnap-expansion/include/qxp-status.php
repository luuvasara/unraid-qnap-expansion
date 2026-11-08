<?php
/* qxp-status.php - the Status tab of qnap-expansion: is the enclosure's card there and working, and what was checked.
 * READ-ONLY: sysfs and Unraid's state files through the plugin kit (kit/EnclosureView.php); runs no command, opens no drive.
 * qxp_collect() returns the checks and the per-card facts; qxp_render() draws them. Tests: tests/status-test.php. */
require_once dirname(__DIR__) . '/kit/EnclosureView.php';

function qxp_root(): string { return (string)(getenv('DRIVEMAP_ROOT') ?: ''); }

/* ['units' => [unit + 'hw' => enclosure_hw()], 'candidates' => [...], 'checks' => [[title, ok|warn|fail|info, detail], ...], 'verdict' => [state, text]] */
function qxp_collect(): array {
  $root = qxp_root();
  $cab = rtrim($root, '/') . '/boot/config/plugins/qnap-expansion/enclosures.json';
  $v = enclosure_view(['cabling_file' => $cab]);
  $checks = [];
  $grp = 'This server';
  $add = function ($t, $s, $d) use (&$checks, &$grp) { $checks[] = [$t, $s, $d, $grp]; };
  if (!$v['units']) {
    $add('Enclosure card', 'fail', $v['candidates']
      ? 'A QNAP controller is in this server, but it is not laid out like a known card: ' . implode('; ', array_map(function ($c) { return "{$c['address']} ({$c['id']}, subsystem {$c['subsystem']})"; }, $v['candidates'])) . '.'
      : 'No QXP card of a known enclosure found (looked for: ' . implode(', ', array_map(function ($p) { return "{$p['card']} for the {$p['name']}"; }, $v['profiles'])) . ').');
  }
  foreach ($v['units'] as &$u) {
    $u['hw'] = enclosure_hw($u, $u['bays'], $root);
    $grp = "{$u['name']} {$u['unit']}"; $n = '';
    $add("Card", 'ok', "{$u['card']} at {$u['switch']}: PCIe switch with " . count($u['controllers']) . " SATA controllers (" . implode(', ', $u['controllers']) . "), subsystem {$u['subsystem']}.");
    $bad = array_filter($u['hw']['controllers'], function ($c) { return $c['driver'] !== 'ahci'; });
    $add("Driver", $bad ? 'fail' : 'ok', $bad ? 'Not bound to the ahci driver: ' . implode(', ', array_map(function ($c) { return $c['address'] . ($c['driver'] !== '' ? " ({$c['driver']})" : ' (no driver)'); }, $bad)) . '.'
      : 'Every controller runs on the kernel\'s standard ahci driver; no extra driver is needed.');
    $down = array_filter($u['links'], function ($l) { return $l['state'] !== 'ok'; });
    $add("PCIe links", $down ? 'warn' : 'ok', $down ? implode('; ', array_map(function ($l) { return "{$l['name']}: " . enclosure_link_text($l['speed'], $l['width']) . ' of ' . enclosure_link_text($l['want_speed'], $l['want_width']) . " ({$l['state']})"; }, $down)) . '.'
      : 'All ' . count($u['links']) . ' links run at the speed and width both ends can do (card: ' . enclosure_link_text($u['links'][0]['speed'] ?? null, $u['links'][0]['width'] ?? null) . ').');
    $errs = [];
    foreach (array_merge([['address' => $u['switch'], 'aer' => $u['hw']['switch_aer']]], $u['hw']['controllers']) as $c)
      foreach ($c['aer'] as $k => $x) if ($x) $errs[] = "{$c['address']} $k $x";
    $add("PCIe errors", $errs ? 'warn' : 'ok', $errs ? 'Errors counted since boot (AER): ' . implode(', ', $errs) . '. Correctable ones are fixed by the link itself; many of them point at a loose card or riser.' : 'No PCIe errors counted since boot (AER).');
    $ports = []; $slow = [];
    foreach ($u['hw']['controllers'] as $c) foreach ($c['ports'] as $p) { $ports[] = $p; if ($p['device'] !== '' && $p['limit'] !== '' && $p['speed'] !== $p['limit']) $slow[] = ($p['bay'] ?: $p['device']) . " {$p['speed']} of {$p['limit']}"; }
    $drives = count(array_filter($u['bays'], function ($b) { return $b['device'] !== ''; }));
    $add("Drives", $slow ? 'warn' : 'ok', "$drives drives in " . count($u['bays']) . ' bays' . ($u['unplaced'] ? ', ' . count($u['unplaced']) . ' on the card without a bay' : '') . '. ' . ($slow ? 'SATA links below the port\'s speed: ' . implode(', ', $slow) . '.' : 'Every SATA link runs at the port\'s full speed.'));
    $mapped = $u['cabling'] && $u['ports_known'];
    $add("Cabling", $u['confirmed'] ? 'ok' : 'warn', !$mapped ? 'The bays of this profile are not mapped.' : ($u['confirmed'] ? 'Confirmed: every drive is shown in its bay.'
      : 'Not confirmed: the bays follow the unit the profile was measured on. Confirm it on the Enclosures tab with Locate.'));
    $ems = array_unique(array_map(function ($c) { return $c['ems']; }, $u['hw']['controllers']), SORT_REGULAR);
    $add("Fans, LEDs, firmware", 'info', 'Not reachable from the server: the enclosure shows no SES device' . ($u['hw']['ses'] ? ' (an SES device exists, but not for this card)' : '')
      . ', no USB link' . ($ems === [false] ? ', and its controllers offer no AHCI enclosure management (LED messages)' : '') . '. The enclosure runs its own fans and lights; see the notes on the Enclosures tab.');
  }
  unset($u);
  $states = array_column($checks, 1);
  $verdict = in_array('fail', $states, true) ? ['fail', $v['units'] ? 'The enclosure is found, but something is wrong: see the checks.' : 'No enclosure card found.']
    : (in_array('warn', $states, true) ? ['warn', 'The enclosure works; some checks need a look.'] : ['ok', 'The enclosure works: card, driver, links and drives are fine.']);
  return ['units' => $v['units'], 'candidates' => $v['candidates'], 'checks' => $checks, 'verdict' => $verdict];
}

function qxp_render(array $s): string {
  $h = function ($x) { return htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8'); };
  $icon = ['ok' => 'fa-check green-text', 'warn' => 'fa-warning orange-text', 'fail' => 'fa-times red-text', 'info' => 'fa-info-circle'];
  $word = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem', 'info' => 'Note'];
  [$vs, $vt] = $s['verdict'];
  $o = '<div class="title"><span class="left"><i class="fa fa-heartbeat title"></i>Status</span></div>'
     . '<p class="qxp-verdict"><i class="fa ' . $icon[$vs] . '" aria-hidden="true"></i> <strong>' . $h($vt) . "</strong></p>\n"
     . '';
  $groups = [];
  foreach ($s['checks'] as $c) $groups[$c[3]][] = $c;
  foreach ($groups as $g => $list) {
    $o .= '<div class="qxp-scroll"><table class="unraid qxp-t qxp-checks"><caption class="qxp-cap">' . $h("$g: what was checked") . '</caption><thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Details</th></tr></thead><tbody>';
    foreach ($list as [$t, $st, $d])
      $o .= '<tr><th scope="row">' . $h($t) . '</th><td class="qxp-nowrap"><i class="fa ' . $icon[$st] . '" aria-hidden="true"></i> ' . $word[$st] . '</td><td>' . $h($d) . '</td></tr>';
    $o .= "</tbody></table></div>\n";
  }
  foreach ($s['units'] as $u) {
    $o .= '<div class="qxp-scroll"><table class="unraid qxp-t"><caption class="qxp-cap">' . $h("{$u['name']} {$u['unit']}: controllers and SATA ports") . '</caption>'
        . '<thead><tr><th scope="col">Controller</th><th scope="col">Driver</th><th scope="col">PCIe link</th><th scope="col">PCIe errors</th><th scope="col">Port</th><th scope="col">SATA link</th><th scope="col">Bay</th><th scope="col">Drive</th><th scope="col">Firmware</th></tr></thead><tbody>';
    foreach ($u['hw']['controllers'] as $c) {
      $l = null; foreach ($u['links'] as $x) if ($x['to'] === $c['address']) $l = $x;
      $aer = implode(' / ', array_map(function ($x) { return $x === null ? '-' : (string)$x; }, $c['aer']));
      $ports = $c['ports'] ?: [['port_no' => '', 'ata' => '', 'speed' => 'no link', 'limit' => '', 'bay' => '', 'device' => '', 'rev' => '']];
      foreach ($ports as $i => $p) {
        $o .= '<tr>' . ($i === 0 ? '<th scope="rowgroup" rowspan="' . count($ports) . '">' . $h(($l['name'] ?? "Controller {$c['n']}") . "\n{$c['address']}") . '</th><td rowspan="' . count($ports) . '">' . $h($c['driver'] ?: 'none')
              . '</td><td rowspan="' . count($ports) . '">' . $h($l ? enclosure_link_text($l['speed'], $l['width']) : '') . '</td><td rowspan="' . count($ports) . '" title="correctable / non-fatal / fatal">' . $h($aer) . '</td>' : '')
            . '<td>' . $h($p['port_no'] !== '' ? "{$p['port_no']} ({$p['ata']})" : '-') . '</td><td>' . $h($p['speed']) . '</td><td>' . $h($p['bay'] ?: '-') . '</td><td>' . $h($p['device'] ?: '-') . '</td><td>' . $h($p['rev'] ?: '-') . '</td></tr>';
      }
    }
    $o .= "</tbody></table></div>\n";
  }
  return $o;
}
