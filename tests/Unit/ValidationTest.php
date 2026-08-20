<?php
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/helpers/InputValidator.php';
require_once dirname(__DIR__, 2) . '/app/helpers/Money.php';

check('normaliza email', InputValidator::email(' USER@Example.COM ') === 'user@example.com');
check('rechaza email inválido', InputValidator::email('x@') === null);
check('acepta identificador positivo', InputValidator::id('42') === 42);
check('rechaza identificador negativo', InputValidator::id('-1') === null);
check('valida teléfono internacional', InputValidator::phone('+34 600 123 123') === '+34600123123');
check('rechaza control en texto', InputValidator::text("hola\0", 20) === null);
check('valida fecha real', InputValidator::date('2026-02-28') === '2026-02-28');
check('rechaza fecha imposible', InputValidator::date('2026-02-30') === null);
check('dinero 29.99 exacto', Money::cents('29.99') === 2999);
check('dinero 49.95 exacto', Money::multiply('49.95', 2) === '99.90');
check('dinero cero', Money::decimal(Money::cents('0')) === '0.00');
check('rechaza más de dos decimales', InputValidator::money('1.999') === null);
check('rechaza dinero negativo', InputValidator::money('-0.01') === null);
if (in_array('--force-failure', $argv, true)) check('sonda deliberadamente falsa', false);
finishTests();
