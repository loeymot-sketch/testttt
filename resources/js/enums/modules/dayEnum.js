// [ONB-01 2026-08-28] `key` ajoute : l'ecran des creneaux horaires affichait
// « Monday, Tuesday… » en anglais, sous un titre francais, sur la page meme ou
// un restaurateur declare ses heures d'ouverture. Les identifiants sont
// INCHANGES — ils sont le contrat de donnee avec `time_slots.day` (0 = dimanche,
// convention de date('w')). Seul l'affichage passe desormais par l'i18n.
const dayEnum = Object.freeze([
    {
        id: 1,
        name: "Monday",
        key: "day_monday",
    },
    {
        id: 2,
        name: "Tuesday",
        key: "day_tuesday",
    },
    {
        id: 3,
        name: "Wednesday",
        key: "day_wednesday",
    },
    {
        id: 4,
        name: "Thursday",
        key: "day_thursday",
    },
    {
        id: 5,
        name: "Friday",
        key: "day_friday",
    },
    {
        id: 6,
        name: "Saturday",
        key: "day_saturday",
    },
    {
        id: 0,
        name: "Sunday",
        key: "day_sunday",
    },
]);
export default dayEnum;
