<?php
/**
 * Slovenska imena dni in datumov.
 *
 * Pišemo jih ročno, ker se na PHP locale (strftime/IntlDateFormatter) na
 * shared hostingu ni mogoče zanesti — slovenski locale pogosto ni nameščen.
 */
final class SlovenianDate
{
    /** 1 = ponedeljek ... 7 = nedelja */
    private const DAYS = [
        1 => 'ponedeljek',
        2 => 'torek',
        3 => 'sreda',
        4 => 'četrtek',
        5 => 'petek',
        6 => 'sobota',
        7 => 'nedelja',
    ];

    /** Rodilnik: "18. septembra" */
    private const MONTHS_GENITIVE = [
        1 => 'januarja',   2 => 'februarja', 3 => 'marca',     4 => 'aprila',
        5 => 'maja',       6 => 'junija',    7 => 'julija',    8 => 'avgusta',
        9 => 'septembra', 10 => 'oktobra',  11 => 'novembra', 12 => 'decembra',
    ];

    public static function dayName(int $isoDayOfWeek): string
    {
        return self::DAYS[$isoDayOfWeek] ?? '';
    }

    /** "četrtek, 18. septembra" */
    public static function longDate(DateTimeInterface $date): string
    {
        $day   = self::dayName((int) $date->format('N'));
        $month = self::MONTHS_GENITIVE[(int) $date->format('n')];
        return $day . ', ' . (int) $date->format('j') . '. ' . $month;
    }

    /**
     * Relativni opis, kot bi ga povedal človek: "jutri", "čez tri dni".
     * Vrne null, kadar je datum predaleč, da bi bil relativni opis smiseln.
     */
    public static function relativeDay(DateTimeInterface $date, DateTimeInterface $today): ?string
    {
        $diff = (int) $today->diff($date)->format('%r%a');

        switch ($diff) {
            case 0:  return 'danes';
            case 1:  return 'jutri';
            case 2:  return 'pojutrišnjem';
            case -1: return 'včeraj';
        }

        if ($diff > 2 && $diff <= 7) {
            return 'čez ' . self::daysPhrase($diff);
        }
        return null;
    }

    /** Pravilna oblika števnika: 1 dan, 2 dneva, 3 dni, 5 dni */
    public static function daysPhrase(int $days): string
    {
        $days = abs($days);
        if ($days === 1) {
            return 'en dan';
        }
        if ($days === 2) {
            return 'dva dneva';
        }
        if ($days === 3) {
            return 'tri dni';
        }
        if ($days === 4) {
            return 'štiri dni';
        }
        return $days . ' dni';
    }
}
