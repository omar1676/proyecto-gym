<?php

require_once __DIR__ . '/InputValidator.php';
require_once __DIR__ . '/Iban.php';

/** Validación compartida por el alta y la edición del perfil de un socio. */
final class SocioProfileValidator
{
    /**
     * @return array{values:array<string,?string>,errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $display = static function ($value, int $max): string {
            $raw = (string) $value;
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $raw)) return '';
            return mb_substr(trim($raw), 0, $max);
        };

        $values = [
            'nombre' => $display($input['nombre'] ?? '', 100),
            'apellidos' => $display($input['apellidos'] ?? '', 150),
            'dni' => strtoupper($display($input['dni'] ?? '', 20)),
            'email' => mb_strtolower($display($input['email'] ?? '', 190)),
            'telefono' => $display($input['telefono'] ?? '', 20),
            'iban' => strtoupper($display($input['iban'] ?? '', 40)),
        ];
        $errors = [];

        $nombre = InputValidator::text($input['nombre'] ?? '', 100);
        if ($nombre === null) $errors['nombre'] = 'Introduce un nombre válido (máximo 100 caracteres).';
        else $values['nombre'] = $nombre;

        $apellidos = InputValidator::text($input['apellidos'] ?? '', 150);
        if ($apellidos === null) $errors['apellidos'] = 'Introduce apellidos válidos (máximo 150 caracteres).';
        else $values['apellidos'] = $apellidos;

        $dni = InputValidator::dniNie($input['dni'] ?? '');
        if ($dni === null) $errors['dni'] = 'DNI/NIE no válido. Revisa el formato y la letra.';
        else $values['dni'] = $dni;

        $email = InputValidator::email($input['email'] ?? '');
        if ($email === null) $errors['email'] = 'Email no válido.';
        else $values['email'] = $email;

        $telefonoRaw = trim((string) ($input['telefono'] ?? ''));
        if ($telefonoRaw === '') {
            $values['telefono'] = null;
        } else {
            $telefono = InputValidator::phone($telefonoRaw);
            if ($telefono === null) $errors['telefono'] = 'Teléfono no válido.';
            else $values['telefono'] = $telefono;
        }

        $ibanRaw = trim((string) ($input['iban'] ?? ''));
        if ($ibanRaw === '') {
            $values['iban'] = null;
        } else {
            $iban = Iban::normalizar($ibanRaw);
            if (!Iban::esValido($iban)) $errors['iban'] = 'IBAN no válido. Revisa la longitud y el dígito de control.';
            else $values['iban'] = $iban;
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
