<?php

namespace App\Services\Assistant\MissionLocale;

/**
 * [ONB-13 2026-08-28] Signale qu'une mission n'a pas pu s'appliquer entierement.
 *
 * Sert uniquement a faire annuler la transaction depuis l'interieur de la cloture.
 * Elle ne remonte jamais au controleur : `ExecuteurDeMission::appliquer()` l'attrape
 * et rend un rapport lisible.
 *
 * Une mission s'applique entierement ou pas du tout : un catalogue a moitie modifie
 * serait pire que pas modifie, parce que le commercant ne saurait pas ou il en est.
 */
class MissionPartielleException extends \RuntimeException
{
}
