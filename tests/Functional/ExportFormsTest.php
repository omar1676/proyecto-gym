<?php

$root = dirname(__DIR__, 2);
$ok = 0;
$fail = 0;

$check = static function (string $label, bool $condition) use (&$ok, &$fail): void {
    if ($condition) {
        $ok++;
        echo "  OK   {$label}\n";
        return;
    }
    $fail++;
    echo "  FALLO {$label}\n";
};

foreach (['reportes.php', 'ventas.php'] as $view) {
    $html = (string) file_get_contents($root . '/app/views/admin/' . $view);
    $forms = [];
    preg_match_all('/<form\b[^>]*>.*?<\/form>/si', $html, $forms);
    $nested = false;
    foreach ($forms[0] ?? [] as $form) {
        if (preg_match_all('/<form\b/i', $form) > 1) {
            $nested = true;
            break;
        }
    }
    $check($view . ' no anida formularios', !$nested);
    $check($view . ' exporta mediante POST', str_contains($html, 'method="POST" action="index.php?action=admin_exportar_ventas_csv"'));
    $check($view . ' incluye CSRF en exportación', str_contains($html, '<?= Csrf::field() ?>'));
}

echo "RESUMEN: {$ok} correctas, {$fail} fallidas\n";
exit($fail === 0 ? 0 : 1);
