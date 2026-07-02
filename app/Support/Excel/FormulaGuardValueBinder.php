<?php

namespace App\Support\Excel;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * [ULTRA-AUDIT V3 2026-07-02 — P2 CSV/formula injection] Binder global anti-injection pour TOUS
 * les exports Excel (~20). Sans lui, une valeur commençant par `= + - @` (tab/CR) est liée en
 * FORMULE par le binder par défaut (dataType=f) → injection CSV/Excel amorçable via une donnée
 * contrôlée par l'utilisateur (ex. le `name` d'un signup public → CustomerExport). À l'ouverture
 * du fichier, `=cmd|'/c calc'!A1` s'exécute (RCE côté poste).
 *
 * Défense OWASP : neutraliser toute chaîne à caractère de tête dangereux → cellule TEXTE explicite
 * préfixée d'une apostrophe (marqueur texte). Les valeurs numériques/dates/booléennes légitimes
 * passent au binder par défaut inchangées (pas de régression sur montants/quantités).
 */
class FormulaGuardValueBinder extends DefaultValueBinder
{
    /** Caractères de tête qui déclenchent une évaluation de formule (Excel / Google Sheets / CSV). */
    private const DANGEROUS_LEADING = ['=', '+', '-', '@', "\t", "\r"];

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::DANGEROUS_LEADING, true)) {
            // Force en texte + préfixe apostrophe → jamais interprété comme formule (xlsx ET ré-export CSV).
            $cell->setValueExplicit("'" . $value, DataType::TYPE_STRING);
            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
