<?php

declare(strict_types=1);

/**
 * iHymns — IETF BCP 47 reference data (#681)
 *
 * Static data-only file consumed by migrate-ietf-bcp47-language.php.
 * Lives separately so the migration runner stays focused on logic and
 * the data tables are easy to extend (just append new entries; re-run
 * the migration; INSERT IGNORE picks up the new rows).
 *
 * SCRIPTS  — ISO 15924 four-letter codes. Curated to the ~25 most
 *            commonly used in real-world hymnal corpora; extend as
 *            curators encounter new ones.
 * REGIONS  — ISO 3166-1 alpha-2 codes. Full set, sorted alphabetically
 *            by code so a future diff is easy to read.
 *
 * Both arrays use the shape:
 *     ['code' => 'Latn', 'name' => 'Latin', 'native' => '']
 * (`native` is optional and omitted for region rows.)
 */

const SCRIPTS = [
    ['code' => 'Latn', 'name' => 'Latin'],
    ['code' => 'Cyrl', 'name' => 'Cyrillic'],
    ['code' => 'Grek', 'name' => 'Greek'],
    ['code' => 'Arab', 'name' => 'Arabic'],
    ['code' => 'Hebr', 'name' => 'Hebrew'],
    ['code' => 'Hans', 'name' => 'Han Simplified',  'native' => '简体中文'],
    ['code' => 'Hant', 'name' => 'Han Traditional', 'native' => '繁體中文'],
    ['code' => 'Jpan', 'name' => 'Japanese',        'native' => '日本語'],
    ['code' => 'Kore', 'name' => 'Korean',          'native' => '한국어'],
    ['code' => 'Deva', 'name' => 'Devanagari'],
    ['code' => 'Beng', 'name' => 'Bengali'],
    ['code' => 'Guru', 'name' => 'Gurmukhi'],
    ['code' => 'Gujr', 'name' => 'Gujarati'],
    ['code' => 'Knda', 'name' => 'Kannada'],
    ['code' => 'Mlym', 'name' => 'Malayalam'],
    ['code' => 'Taml', 'name' => 'Tamil'],
    ['code' => 'Telu', 'name' => 'Telugu'],
    ['code' => 'Thaa', 'name' => 'Thaana'],
    ['code' => 'Thai', 'name' => 'Thai'],
    ['code' => 'Mymr', 'name' => 'Myanmar'],
    ['code' => 'Khmr', 'name' => 'Khmer'],
    ['code' => 'Laoo', 'name' => 'Lao'],
    ['code' => 'Sinh', 'name' => 'Sinhala'],
    ['code' => 'Ethi', 'name' => 'Ethiopic'],
    ['code' => 'Geor', 'name' => 'Georgian'],
    ['code' => 'Armn', 'name' => 'Armenian'],
    ['code' => 'Zyyy', 'name' => 'Common'],
    ['code' => 'Zxxx', 'name' => 'Unwritten'],
];

const REGIONS = [
    /* ISO 3166-1 alpha-2 (#681). Sorted by code. Extend at any time
       and re-run the migration; INSERT IGNORE only adds new rows. */

    /* A */
    ['code' => 'AD', 'name' => 'Andorra'],
    ['code' => 'AE', 'name' => 'United Arab Emirates'],
    ['code' => 'AF', 'name' => 'Afghanistan'],
    ['code' => 'AG', 'name' => 'Antigua and Barbuda'],
    ['code' => 'AI', 'name' => 'Anguilla'],
    ['code' => 'AL', 'name' => 'Albania'],
    ['code' => 'AM', 'name' => 'Armenia'],
    ['code' => 'AO', 'name' => 'Angola'],
    ['code' => 'AQ', 'name' => 'Antarctica'],
    ['code' => 'AR', 'name' => 'Argentina'],
    ['code' => 'AS', 'name' => 'American Samoa'],
    ['code' => 'AT', 'name' => 'Austria'],
    ['code' => 'AU', 'name' => 'Australia'],
    ['code' => 'AW', 'name' => 'Aruba'],
    ['code' => 'AX', 'name' => 'Aland Islands'],
    ['code' => 'AZ', 'name' => 'Azerbaijan'],

    /* B */
    ['code' => 'BA', 'name' => 'Bosnia and Herzegovina'],
    ['code' => 'BB', 'name' => 'Barbados'],
    ['code' => 'BD', 'name' => 'Bangladesh'],
    ['code' => 'BE', 'name' => 'Belgium'],
    ['code' => 'BF', 'name' => 'Burkina Faso'],
    ['code' => 'BG', 'name' => 'Bulgaria'],
    ['code' => 'BH', 'name' => 'Bahrain'],
    ['code' => 'BI', 'name' => 'Burundi'],
    ['code' => 'BJ', 'name' => 'Benin'],
    ['code' => 'BL', 'name' => 'Saint Barthelemy'],
    ['code' => 'BM', 'name' => 'Bermuda'],
    ['code' => 'BN', 'name' => 'Brunei Darussalam'],
    ['code' => 'BO', 'name' => 'Bolivia'],
    ['code' => 'BQ', 'name' => 'Bonaire, Sint Eustatius and Saba'],
    ['code' => 'BR', 'name' => 'Brazil'],
    ['code' => 'BS', 'name' => 'Bahamas'],
    ['code' => 'BT', 'name' => 'Bhutan'],
    ['code' => 'BV', 'name' => 'Bouvet Island'],
    ['code' => 'BW', 'name' => 'Botswana'],
    ['code' => 'BY', 'name' => 'Belarus'],
    ['code' => 'BZ', 'name' => 'Belize'],

    /* C */
    ['code' => 'CA', 'name' => 'Canada'],
    ['code' => 'CC', 'name' => 'Cocos (Keeling) Islands'],
    ['code' => 'CD', 'name' => 'Congo, Democratic Republic of the'],
    ['code' => 'CF', 'name' => 'Central African Republic'],
    ['code' => 'CG', 'name' => 'Congo'],
    ['code' => 'CH', 'name' => 'Switzerland'],
    ['code' => 'CI', 'name' => "Cote d'Ivoire"],
    ['code' => 'CK', 'name' => 'Cook Islands'],
    ['code' => 'CL', 'name' => 'Chile'],
    ['code' => 'CM', 'name' => 'Cameroon'],
    ['code' => 'CN', 'name' => 'China'],
    ['code' => 'CO', 'name' => 'Colombia'],
    ['code' => 'CR', 'name' => 'Costa Rica'],
    ['code' => 'CU', 'name' => 'Cuba'],
    ['code' => 'CV', 'name' => 'Cabo Verde'],
    ['code' => 'CW', 'name' => 'Curacao'],
    ['code' => 'CX', 'name' => 'Christmas Island'],
    ['code' => 'CY', 'name' => 'Cyprus'],
    ['code' => 'CZ', 'name' => 'Czechia'],

    /* D */
    ['code' => 'DE', 'name' => 'Germany'],
    ['code' => 'DJ', 'name' => 'Djibouti'],
    ['code' => 'DK', 'name' => 'Denmark'],
    ['code' => 'DM', 'name' => 'Dominica'],
    ['code' => 'DO', 'name' => 'Dominican Republic'],
    ['code' => 'DZ', 'name' => 'Algeria'],

    /* E */
    ['code' => 'EC', 'name' => 'Ecuador'],
    ['code' => 'EE', 'name' => 'Estonia'],
    ['code' => 'EG', 'name' => 'Egypt'],
    ['code' => 'EH', 'name' => 'Western Sahara'],
    ['code' => 'ER', 'name' => 'Eritrea'],
    ['code' => 'ES', 'name' => 'Spain'],
    ['code' => 'ET', 'name' => 'Ethiopia'],

    /* F */
    ['code' => 'FI', 'name' => 'Finland'],
    ['code' => 'FJ', 'name' => 'Fiji'],
    ['code' => 'FK', 'name' => 'Falkland Islands'],
    ['code' => 'FM', 'name' => 'Micronesia, Federated States of'],
    ['code' => 'FO', 'name' => 'Faroe Islands'],
    ['code' => 'FR', 'name' => 'France'],

    /* G */
    ['code' => 'GA', 'name' => 'Gabon'],
    ['code' => 'GB', 'name' => 'United Kingdom'],
    ['code' => 'GD', 'name' => 'Grenada'],
    ['code' => 'GE', 'name' => 'Georgia'],
    ['code' => 'GF', 'name' => 'French Guiana'],
    ['code' => 'GG', 'name' => 'Guernsey'],
    ['code' => 'GH', 'name' => 'Ghana'],
    ['code' => 'GI', 'name' => 'Gibraltar'],
    ['code' => 'GL', 'name' => 'Greenland'],
    ['code' => 'GM', 'name' => 'Gambia'],
    ['code' => 'GN', 'name' => 'Guinea'],
    ['code' => 'GP', 'name' => 'Guadeloupe'],
    ['code' => 'GQ', 'name' => 'Equatorial Guinea'],
    ['code' => 'GR', 'name' => 'Greece'],
    ['code' => 'GS', 'name' => 'South Georgia and the South Sandwich Islands'],
    ['code' => 'GT', 'name' => 'Guatemala'],
    ['code' => 'GU', 'name' => 'Guam'],
    ['code' => 'GW', 'name' => 'Guinea-Bissau'],
    ['code' => 'GY', 'name' => 'Guyana'],

    /* H */
    ['code' => 'HK', 'name' => 'Hong Kong'],
    ['code' => 'HM', 'name' => 'Heard Island and McDonald Islands'],
    ['code' => 'HN', 'name' => 'Honduras'],
    ['code' => 'HR', 'name' => 'Croatia'],
    ['code' => 'HT', 'name' => 'Haiti'],
    ['code' => 'HU', 'name' => 'Hungary'],

    /* I */
    ['code' => 'ID', 'name' => 'Indonesia'],
    ['code' => 'IE', 'name' => 'Ireland'],
    ['code' => 'IL', 'name' => 'Israel'],
    ['code' => 'IM', 'name' => 'Isle of Man'],
    ['code' => 'IN', 'name' => 'India'],
    ['code' => 'IO', 'name' => 'British Indian Ocean Territory'],
    ['code' => 'IQ', 'name' => 'Iraq'],
    ['code' => 'IR', 'name' => 'Iran'],
    ['code' => 'IS', 'name' => 'Iceland'],
    ['code' => 'IT', 'name' => 'Italy'],

    /* J */
    ['code' => 'JE', 'name' => 'Jersey'],
    ['code' => 'JM', 'name' => 'Jamaica'],
    ['code' => 'JO', 'name' => 'Jordan'],
    ['code' => 'JP', 'name' => 'Japan'],

    /* K */
    ['code' => 'KE', 'name' => 'Kenya'],
    ['code' => 'KG', 'name' => 'Kyrgyzstan'],
    ['code' => 'KH', 'name' => 'Cambodia'],
    ['code' => 'KI', 'name' => 'Kiribati'],
    ['code' => 'KM', 'name' => 'Comoros'],
    ['code' => 'KN', 'name' => 'Saint Kitts and Nevis'],
    ['code' => 'KP', 'name' => "Korea, Democratic People's Republic of"],
    ['code' => 'KR', 'name' => 'Korea, Republic of'],
    ['code' => 'KW', 'name' => 'Kuwait'],
    ['code' => 'KY', 'name' => 'Cayman Islands'],
    ['code' => 'KZ', 'name' => 'Kazakhstan'],

    /* L */
    ['code' => 'LA', 'name' => "Lao People's Democratic Republic"],
    ['code' => 'LB', 'name' => 'Lebanon'],
    ['code' => 'LC', 'name' => 'Saint Lucia'],
    ['code' => 'LI', 'name' => 'Liechtenstein'],
    ['code' => 'LK', 'name' => 'Sri Lanka'],
    ['code' => 'LR', 'name' => 'Liberia'],
    ['code' => 'LS', 'name' => 'Lesotho'],
    ['code' => 'LT', 'name' => 'Lithuania'],
    ['code' => 'LU', 'name' => 'Luxembourg'],
    ['code' => 'LV', 'name' => 'Latvia'],
    ['code' => 'LY', 'name' => 'Libya'],

    /* M */
    ['code' => 'MA', 'name' => 'Morocco'],
    ['code' => 'MC', 'name' => 'Monaco'],
    ['code' => 'MD', 'name' => 'Moldova'],
    ['code' => 'ME', 'name' => 'Montenegro'],
    ['code' => 'MF', 'name' => 'Saint Martin (French part)'],
    ['code' => 'MG', 'name' => 'Madagascar'],
    ['code' => 'MH', 'name' => 'Marshall Islands'],
    ['code' => 'MK', 'name' => 'North Macedonia'],
    ['code' => 'ML', 'name' => 'Mali'],
    ['code' => 'MM', 'name' => 'Myanmar'],
    ['code' => 'MN', 'name' => 'Mongolia'],
    ['code' => 'MO', 'name' => 'Macao'],
    ['code' => 'MP', 'name' => 'Northern Mariana Islands'],
    ['code' => 'MQ', 'name' => 'Martinique'],
    ['code' => 'MR', 'name' => 'Mauritania'],
    ['code' => 'MS', 'name' => 'Montserrat'],
    ['code' => 'MT', 'name' => 'Malta'],
    ['code' => 'MU', 'name' => 'Mauritius'],
    ['code' => 'MV', 'name' => 'Maldives'],
    ['code' => 'MW', 'name' => 'Malawi'],
    ['code' => 'MX', 'name' => 'Mexico'],
    ['code' => 'MY', 'name' => 'Malaysia'],
    ['code' => 'MZ', 'name' => 'Mozambique'],

    /* N */
    ['code' => 'NA', 'name' => 'Namibia'],
    ['code' => 'NC', 'name' => 'New Caledonia'],
    ['code' => 'NE', 'name' => 'Niger'],
    ['code' => 'NF', 'name' => 'Norfolk Island'],
    ['code' => 'NG', 'name' => 'Nigeria'],
    ['code' => 'NI', 'name' => 'Nicaragua'],
    ['code' => 'NL', 'name' => 'Netherlands'],
    ['code' => 'NO', 'name' => 'Norway'],
    ['code' => 'NP', 'name' => 'Nepal'],
    ['code' => 'NR', 'name' => 'Nauru'],
    ['code' => 'NU', 'name' => 'Niue'],
    ['code' => 'NZ', 'name' => 'New Zealand'],

    /* O */
    ['code' => 'OM', 'name' => 'Oman'],

    /* P */
    ['code' => 'PA', 'name' => 'Panama'],
    ['code' => 'PE', 'name' => 'Peru'],
    ['code' => 'PF', 'name' => 'French Polynesia'],
    ['code' => 'PG', 'name' => 'Papua New Guinea'],
    ['code' => 'PH', 'name' => 'Philippines'],
    ['code' => 'PK', 'name' => 'Pakistan'],
    ['code' => 'PL', 'name' => 'Poland'],
    ['code' => 'PM', 'name' => 'Saint Pierre and Miquelon'],
    ['code' => 'PN', 'name' => 'Pitcairn'],
    ['code' => 'PR', 'name' => 'Puerto Rico'],
    ['code' => 'PS', 'name' => 'Palestine, State of'],
    ['code' => 'PT', 'name' => 'Portugal'],
    ['code' => 'PW', 'name' => 'Palau'],
    ['code' => 'PY', 'name' => 'Paraguay'],

    /* Q */
    ['code' => 'QA', 'name' => 'Qatar'],

    /* R */
    ['code' => 'RE', 'name' => 'Reunion'],
    ['code' => 'RO', 'name' => 'Romania'],
    ['code' => 'RS', 'name' => 'Serbia'],
    ['code' => 'RU', 'name' => 'Russia'],
    ['code' => 'RW', 'name' => 'Rwanda'],

    /* S */
    ['code' => 'SA', 'name' => 'Saudi Arabia'],
    ['code' => 'SB', 'name' => 'Solomon Islands'],
    ['code' => 'SC', 'name' => 'Seychelles'],
    ['code' => 'SD', 'name' => 'Sudan'],
    ['code' => 'SE', 'name' => 'Sweden'],
    ['code' => 'SG', 'name' => 'Singapore'],
    ['code' => 'SH', 'name' => 'Saint Helena, Ascension and Tristan da Cunha'],
    ['code' => 'SI', 'name' => 'Slovenia'],
    ['code' => 'SJ', 'name' => 'Svalbard and Jan Mayen'],
    ['code' => 'SK', 'name' => 'Slovakia'],
    ['code' => 'SL', 'name' => 'Sierra Leone'],
    ['code' => 'SM', 'name' => 'San Marino'],
    ['code' => 'SN', 'name' => 'Senegal'],
    ['code' => 'SO', 'name' => 'Somalia'],
    ['code' => 'SR', 'name' => 'Suriname'],
    ['code' => 'SS', 'name' => 'South Sudan'],
    ['code' => 'ST', 'name' => 'Sao Tome and Principe'],
    ['code' => 'SV', 'name' => 'El Salvador'],
    ['code' => 'SX', 'name' => 'Sint Maarten (Dutch part)'],
    ['code' => 'SY', 'name' => 'Syria'],
    ['code' => 'SZ', 'name' => 'Eswatini'],

    /* T */
    ['code' => 'TC', 'name' => 'Turks and Caicos Islands'],
    ['code' => 'TD', 'name' => 'Chad'],
    ['code' => 'TF', 'name' => 'French Southern Territories'],
    ['code' => 'TG', 'name' => 'Togo'],
    ['code' => 'TH', 'name' => 'Thailand'],
    ['code' => 'TJ', 'name' => 'Tajikistan'],
    ['code' => 'TK', 'name' => 'Tokelau'],
    ['code' => 'TL', 'name' => 'Timor-Leste'],
    ['code' => 'TM', 'name' => 'Turkmenistan'],
    ['code' => 'TN', 'name' => 'Tunisia'],
    ['code' => 'TO', 'name' => 'Tonga'],
    ['code' => 'TR', 'name' => 'Turkiye'],
    ['code' => 'TT', 'name' => 'Trinidad and Tobago'],
    ['code' => 'TV', 'name' => 'Tuvalu'],
    ['code' => 'TW', 'name' => 'Taiwan'],
    ['code' => 'TZ', 'name' => 'Tanzania'],

    /* U */
    ['code' => 'UA', 'name' => 'Ukraine'],
    ['code' => 'UG', 'name' => 'Uganda'],
    ['code' => 'UM', 'name' => 'United States Minor Outlying Islands'],
    ['code' => 'US', 'name' => 'United States'],
    ['code' => 'UY', 'name' => 'Uruguay'],
    ['code' => 'UZ', 'name' => 'Uzbekistan'],

    /* V */
    ['code' => 'VA', 'name' => 'Holy See'],
    ['code' => 'VC', 'name' => 'Saint Vincent and the Grenadines'],
    ['code' => 'VE', 'name' => 'Venezuela'],
    ['code' => 'VG', 'name' => 'Virgin Islands, British'],
    ['code' => 'VI', 'name' => 'Virgin Islands, U.S.'],
    ['code' => 'VN', 'name' => 'Viet Nam'],
    ['code' => 'VU', 'name' => 'Vanuatu'],

    /* W */
    ['code' => 'WF', 'name' => 'Wallis and Futuna'],
    ['code' => 'WS', 'name' => 'Samoa'],

    /* Y */
    ['code' => 'YE', 'name' => 'Yemen'],
    ['code' => 'YT', 'name' => 'Mayotte'],

    /* Z */
    ['code' => 'ZA', 'name' => 'South Africa'],
    ['code' => 'ZM', 'name' => 'Zambia'],
    ['code' => 'ZW', 'name' => 'Zimbabwe'],

    /* M.49 area codes BCP 47 also accepts as the third subtag.
       These are economic / geographic groupings, useful when a
       songbook serves a region rather than a country (e.g.
       "Latin America" for a Spanish-language collection). Only
       the most-used groupings — extend if curators need more. */
    ['code' => '419', 'name' => 'Latin America and the Caribbean'],
    ['code' => '150', 'name' => 'Europe'],
    ['code' => '002', 'name' => 'Africa'],
    ['code' => '142', 'name' => 'Asia'],
    ['code' => '009', 'name' => 'Oceania'],
    ['code' => '019', 'name' => 'Americas'],
];
