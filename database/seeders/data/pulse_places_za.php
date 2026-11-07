<?php

/*
| South African gazetteer for Dot.Pulse. Approximate public centroids of towns, cities and well known suburbs,
| rounded to two decimals. A suburb row is level "city" whose `city` column holds the metro it belongs to, so
| "same city" includes the suburbs of one metro. Never a person's position.
|
| provinces: name => [lat, lng]
| towns:     province => ["Town|lat|lng", ...]   (the town is its own city)
| suburbs:   "Metro|Province" => ["Suburb|lat|lng", ...]
*/

return [

    'provinces' => [
        'Eastern Cape' => [-32.3, 26.4],
        'Free State' => [-28.5, 26.8],
        'Gauteng' => [-26.2, 28.1],
        'KwaZulu-Natal' => [-28.5, 30.9],
        'Limpopo' => [-23.4, 29.4],
        'Mpumalanga' => [-25.5, 30.0],
        'North West' => [-26.7, 25.5],
        'Northern Cape' => [-29.7, 21.8],
        'Western Cape' => [-33.2, 21.9],
    ],

    'towns' => [
        'Gauteng' => [
            'Johannesburg|-26.20|28.04', 'Pretoria|-25.75|28.19', 'Centurion|-25.86|28.19', 'Midrand|-25.99|28.13', 'Randburg|-26.09|28.00',
            'Roodepoort|-26.16|27.87', 'Krugersdorp|-26.10|27.77', 'Soweto|-26.27|27.86', 'Germiston|-26.22|28.17', 'Boksburg|-26.21|28.26',
            'Benoni|-26.19|28.32', 'Brakpan|-26.24|28.37', 'Springs|-26.25|28.44', 'Kempton Park|-26.10|28.23', 'Edenvale|-26.14|28.16',
            'Alberton|-26.27|28.12', 'Vereeniging|-26.67|27.93', 'Vanderbijlpark|-26.70|27.84', 'Westonaria|-26.32|27.65', 'Carletonville|-26.36|27.40',
            'Tembisa|-25.99|28.23', 'Alexandra|-26.10|28.10', 'Mamelodi|-25.71|28.39', 'Soshanguve|-25.52|28.10', 'Ga-Rankuwa|-25.62|28.00',
            'Hammanskraal|-25.40|28.28', 'Bronkhorstspruit|-25.81|28.74', 'Heidelberg|-26.50|28.36', 'Meyerton|-26.56|28.02', 'Lenasia|-26.32|27.83',
            'Diepsloot|-25.93|28.01', 'Orange Farm|-26.47|27.85', 'Katlehong|-26.34|28.15', 'Thokoza|-26.36|28.15', 'Daveyton|-26.15|28.40',
            'Cullinan|-25.68|28.52', 'Magaliesburg|-26.00|27.55', 'Muldersdrift|-26.03|27.87', 'Sebokeng|-26.59|27.84', 'Evaton|-26.54|27.90',
        ],
        'Western Cape' => [
            'Cape Town|-33.92|18.42', 'Stellenbosch|-33.93|18.86', 'Paarl|-33.73|18.97', 'Franschhoek|-33.91|19.12', 'Worcester|-33.65|19.45',
            'George|-33.96|22.46', 'Knysna|-34.04|23.05', 'Plettenberg Bay|-34.05|23.37', 'Mossel Bay|-34.18|22.14', 'Oudtshoorn|-33.59|22.20',
            'Hermanus|-34.42|19.24', 'Swellendam|-34.02|20.44', 'Caledon|-34.23|19.43', 'Saldanha|-33.01|17.94', 'Vredenburg|-32.91|17.99',
            'Langebaan|-33.09|18.03', 'Malmesbury|-33.46|18.73', 'Wellington|-33.64|19.01', 'Ceres|-33.37|19.31', 'Robertson|-33.80|19.88',
            'Montagu|-33.79|20.12', 'Bredasdorp|-34.53|20.04', 'Beaufort West|-32.36|22.58', 'Laingsburg|-33.19|20.86', 'Citrusdal|-32.59|19.01',
            'Clanwilliam|-32.18|18.89', 'Piketberg|-32.90|18.76', 'Riversdale|-34.09|21.26', 'Wilderness|-33.99|22.58', 'Sedgefield|-34.02|22.81',
            'Gansbaai|-34.58|19.35', 'Prince Albert|-33.22|22.03', 'Atlantis|-33.57|18.49', 'Darling|-33.38|18.38', 'Velddrif|-32.77|18.17',
            'Paternoster|-32.81|17.89', 'Lamberts Bay|-32.09|18.31', 'Still Bay|-34.37|21.42', 'Ladismith|-33.49|21.26', 'Tulbagh|-33.28|19.14',
            'Greyton|-34.05|19.61', 'Kleinmond|-34.34|19.03', 'Struisbaai|-34.80|19.80', 'Hartenbos|-34.12|22.12', 'De Rust|-33.49|22.52',
        ],
        'KwaZulu-Natal' => [
            'Durban|-29.86|31.02', 'Pietermaritzburg|-29.62|30.39', 'Richards Bay|-28.78|32.04', 'Newcastle|-27.76|29.93', 'Ladysmith|-28.56|29.78',
            'Empangeni|-28.75|31.90', 'Port Shepstone|-30.74|30.45', 'Margate|-30.86|30.37', 'Ballito|-29.54|31.21', 'KwaDukuza|-29.33|31.29',
            'Estcourt|-29.00|29.88', 'Vryheid|-27.77|30.79', 'Ulundi|-28.33|31.42', 'Mtubatuba|-28.42|32.18', 'Howick|-29.48|30.23',
            'Underberg|-29.79|29.50', 'Greytown|-29.07|30.59', 'Eshowe|-28.89|31.47', 'Pongola|-27.38|31.62', 'Dundee|-28.17|30.23',
            'Harding|-30.58|29.88', 'Kokstad|-30.55|29.43', 'Scottburgh|-30.29|30.75', 'Hluhluwe|-28.02|32.28', 'Amanzimtoti|-30.05|30.88',
            'Pinetown|-29.82|30.86', 'Umlazi|-29.97|30.89', 'Chatsworth|-29.92|30.89', 'Phoenix|-29.70|31.00', 'Umhlanga|-29.73|31.08',
            'Westville|-29.83|30.93', 'Kloof|-29.78|30.83', 'Hillcrest|-29.78|30.76', 'Verulam|-29.65|31.05', 'Tongaat|-29.57|31.12',
            'Mooi River|-29.21|29.99', 'Winterton|-28.81|29.53', 'Utrecht|-27.65|30.32', 'Melmoth|-28.60|31.42', 'Richmond|-29.87|30.27',
        ],
        'Eastern Cape' => [
            'Gqeberha|-33.96|25.60', 'East London|-33.02|27.91', 'Mthatha|-31.59|28.78', 'Makhanda|-33.31|26.52', 'Komani|-31.90|26.88',
            'Kariega|-33.76|25.40', 'Jeffreys Bay|-34.05|24.91', 'Humansdorp|-34.03|24.77', 'Cradock|-32.16|25.62', 'Graaff-Reinet|-32.25|24.54',
            'Aliwal North|-30.69|26.71', 'Bhisho|-32.85|27.44', 'Qonce|-32.88|27.40', 'Gcuwa|-32.33|28.15', 'Port Alfred|-33.59|26.89',
            'Fort Beaufort|-32.78|26.63', 'Somerset East|-32.72|25.59', 'Lusikisiki|-31.37|29.58', 'Port St Johns|-31.62|29.54', 'Mdantsane|-32.94|27.77',
            'Despatch|-33.80|25.47', 'Kenton-on-Sea|-33.69|26.67', 'Maclear|-31.07|28.35', 'Stutterheim|-32.57|27.42', 'Molteno|-31.40|26.36',
            'Jansenville|-32.94|24.67', 'Hogsback|-32.58|26.93', 'St Francis Bay|-34.17|24.83', 'Cintsa|-32.83|28.10', 'Tsolo|-31.31|28.77',
        ],
        'Free State' => [
            'Bloemfontein|-29.12|26.21', 'Welkom|-27.98|26.73', 'Kroonstad|-27.65|27.23', 'Bethlehem|-28.23|28.31', 'Sasolburg|-26.81|27.82',
            'Parys|-26.90|27.46', 'Virginia|-28.10|26.87', 'Harrismith|-28.28|29.13', 'Phuthaditjhaba|-28.52|28.82', 'Ficksburg|-28.87|27.88',
            'Odendaalsrus|-27.87|26.69', 'Senekal|-28.32|27.63', 'Ladybrand|-29.19|27.46', 'Botshabelo|-29.24|26.71', 'Thaba Nchu|-29.21|26.84',
            'Clarens|-28.51|28.42', 'Zastron|-30.30|27.09', 'Heilbron|-27.28|27.97', 'Winburg|-28.52|27.00', 'Frankfort|-27.28|28.50',
        ],
        'Limpopo' => [
            'Polokwane|-23.90|29.45', 'Thohoyandou|-23.00|30.48', 'Tzaneen|-23.83|30.16', 'Mokopane|-24.19|29.01', 'Musina|-22.34|30.04',
            'Makhado|-23.05|29.91', 'Phalaborwa|-23.94|31.14', 'Lephalale|-23.67|27.74', 'Bela-Bela|-24.88|28.29', 'Modimolle|-24.70|28.40',
            'Burgersfort|-24.67|30.32', 'Giyani|-23.30|30.71', 'Hoedspruit|-24.35|30.95', 'Groblersdal|-25.17|29.40', 'Marble Hall|-24.97|29.30',
            'Mookgophong|-24.52|28.72', 'Seshego|-23.85|29.40', 'Lebowakgomo|-24.20|29.50', 'Thabazimbi|-24.60|27.40', 'Modjadjiskloof|-23.67|30.18',
        ],
        'Mpumalanga' => [
            'Mbombela|-25.47|30.97', 'eMalahleni|-25.87|29.23', 'Secunda|-26.55|29.17', 'Middelburg|-25.77|29.46', 'Standerton|-26.95|29.24',
            'White River|-25.33|31.01', 'Barberton|-25.79|31.05', 'Sabie|-25.10|30.78', 'Mashishing|-25.10|30.45', 'Graskop|-24.94|30.84',
            'Hazyview|-25.05|31.13', 'Komatipoort|-25.43|31.95', 'Ermelo|-26.53|29.98', 'eMkhondo|-27.00|30.81', 'Bethal|-26.46|29.47',
            'Kriel|-26.25|29.22', 'Malelane|-25.49|31.52', 'Volksrust|-27.36|29.88', 'Delmas|-26.15|28.68', 'Dullstroom|-25.42|30.10',
            'Belfast|-25.69|30.03', 'Machadodorp|-25.67|30.25',
        ],
        'North West' => [
            'Mahikeng|-25.86|25.64', 'Rustenburg|-25.67|27.24', 'Klerksdorp|-26.85|26.67', 'Potchefstroom|-26.71|27.10', 'Brits|-25.63|27.78',
            'Vryburg|-26.96|24.73', 'Lichtenburg|-26.15|26.16', 'Zeerust|-25.54|26.08', 'Hartbeespoort|-25.75|27.97', 'Orkney|-26.98|26.67',
            'Stilfontein|-26.85|26.77', 'Wolmaransstad|-27.20|25.98', 'Ventersdorp|-26.31|26.80', 'Sun City|-25.34|27.09', 'Marikana|-25.70|27.48',
            'Schweizer-Reneke|-27.19|25.33', 'Taung|-27.53|24.78', 'Bloemhof|-27.65|25.60', 'Mmabatho|-25.85|25.62', 'Ga-Mothibistad|-25.80|25.60',
        ],
        'Northern Cape' => [
            'Kimberley|-28.74|24.77', 'Upington|-28.45|21.26', 'Springbok|-29.66|17.89', 'De Aar|-30.65|24.01', 'Kuruman|-27.46|23.43',
            'Kathu|-27.70|23.05', 'Postmasburg|-28.33|23.05', 'Calvinia|-31.47|19.78', 'Colesberg|-30.72|25.10', 'Prieska|-29.66|22.74',
            'Port Nolloth|-29.25|16.87', 'Alexander Bay|-28.60|16.48', 'Kakamas|-28.77|20.62', 'Carnarvon|-30.97|22.13', 'Victoria West|-31.40|23.12',
            'Barkly West|-28.54|24.51', 'Jan Kempdorp|-27.93|24.82', 'Hartswater|-27.75|24.80', 'Douglas|-29.05|23.77', 'Sutherland|-32.40|20.66',
            'Pofadder|-29.13|19.39', 'Hanover|-31.07|24.45',
        ],
    ],

    'suburbs' => [
        'Johannesburg|Gauteng' => [
            'Sandton|-26.11|28.06', 'Rosebank|-26.15|28.04', 'Fourways|-26.01|28.01', 'Bryanston|-26.06|28.02', 'Melville|-26.18|28.00',
            'Braamfontein|-26.19|28.03', 'Maboneng|-26.20|28.06', 'Parktown|-26.18|28.04', 'Houghton|-26.17|28.06', 'Hyde Park|-26.13|28.04',
            'Northcliff|-26.14|27.96', 'Linden|-26.13|27.99', 'Kyalami|-25.99|28.06', 'Wynberg|-26.11|28.09', 'Jeppestown|-26.20|28.07',
            'Observatory|-26.19|28.07', 'Norwood|-26.15|28.08', 'Greenside|-26.15|28.01', 'Emmarentia|-26.15|28.00', 'Cresta|-26.13|27.96',
            'Sunninghill|-26.03|28.06', 'Rivonia|-26.06|28.06', 'Morningside|-26.09|28.06', 'Dainfern|-25.99|28.00', 'Randpark Ridge|-26.10|27.93',
            'Craighall|-26.13|28.02', 'Illovo|-26.13|28.05', 'Bedfordview|-26.18|28.14', 'Edenburg|-26.06|28.07', 'Waterfall|-26.01|28.10',
            'Randburg Central|-26.09|28.00', 'Fordsburg|-26.21|28.01', 'Mayfair|-26.21|28.00', 'Booysens|-26.24|28.01', 'City Deep|-26.24|28.07',
        ],
        'Pretoria|Gauteng' => [
            'Hatfield|-25.75|28.24', 'Brooklyn|-25.77|28.24', 'Menlyn|-25.78|28.28', 'Arcadia|-25.74|28.21', 'Sunnyside|-25.76|28.21',
            'Waterkloof|-25.79|28.24', 'Lynnwood|-25.77|28.27', 'Garsfontein|-25.80|28.30', 'Silverton|-25.75|28.30', 'Montana|-25.66|28.26',
            'Pretoria North|-25.69|28.17', 'Faerie Glen|-25.79|28.32', 'Moreleta Park|-25.81|28.31', 'Wonderboom|-25.68|28.20', 'Centurion CBD|-25.86|28.19',
            'Irene|-25.88|28.21', 'Pretoria West|-25.75|28.14', 'Capital Park|-25.73|28.19', 'Pretoria Gardens|-25.74|28.12', 'Rietondale|-25.73|28.23',
        ],
        'Cape Town|Western Cape' => [
            'Cape Town CBD|-33.92|18.42', 'Bellville|-33.90|18.63', 'Durbanville|-33.83|18.65', 'Century City|-33.89|18.51', 'Claremont|-33.98|18.47',
            'Constantia|-34.02|18.42', 'Camps Bay|-33.95|18.38', 'Sea Point|-33.92|18.39', 'Green Point|-33.91|18.41', 'Woodstock|-33.93|18.45',
            'Observatory|-33.94|18.47', 'Rondebosch|-33.96|18.47', 'Newlands|-33.97|18.45', 'Wynberg|-34.00|18.47', 'Muizenberg|-34.10|18.47',
            'Fish Hoek|-34.14|18.43', 'Simons Town|-34.19|18.43', 'Khayelitsha|-34.04|18.67', 'Mitchells Plain|-34.05|18.62', 'Gugulethu|-33.98|18.57',
            'Langa|-33.94|18.53', 'Athlone|-33.96|18.50', 'Parow|-33.91|18.60', 'Brackenfell|-33.88|18.69', 'Kuils River|-33.93|18.68',
            'Table View|-33.82|18.49', 'Milnerton|-33.87|18.50', 'Somerset West|-34.08|18.85', 'Strand|-34.12|18.83', 'Gordons Bay|-34.16|18.87',
            'Hout Bay|-34.04|18.36', 'Kraaifontein|-33.84|18.72', 'Blouberg|-33.80|18.46', 'Pinelands|-33.94|18.50', 'Tokai|-34.06|18.43',
            'Bishop Lavis|-33.93|18.57', 'Goodwood|-33.91|18.55', 'Epping|-33.94|18.55', 'Maitland|-33.92|18.50', 'Salt River|-33.93|18.46',
        ],
        'Durban|KwaZulu-Natal' => [
            'Berea|-29.85|31.00', 'Morningside|-29.83|31.01', 'La Lucia|-29.75|31.07', 'Glenwood|-29.87|30.99', 'Musgrave|-29.84|31.00',
            'Durban North|-29.79|31.03', 'Umhlanga Rocks|-29.72|31.09', 'Springfield|-29.82|31.00', 'Bluff|-29.93|31.00', 'Mobeni|-29.95|30.97',
            'Sydenham|-29.83|30.96', 'Greyville|-29.84|31.03', 'Point|-29.87|31.04', 'Overport|-29.84|30.99', 'Pinetown Central|-29.82|30.86',
            'Cato Manor|-29.85|30.97', 'KwaMashu|-29.74|30.99', 'Inanda|-29.69|30.94', 'Isipingo|-29.99|30.94', 'Umbilo|-29.87|30.99',
        ],
        'Gqeberha|Eastern Cape' => [
            'Walmer|-33.98|25.59', 'Summerstrand|-34.01|25.67', 'Newton Park|-33.95|25.56', 'Humewood|-33.98|25.65', 'Korsten|-33.91|25.60',
            'Motherwell|-33.80|25.60', 'Zwide|-33.88|25.59', 'Kabega Park|-33.95|25.50', 'Sunridge Park|-33.93|25.52', 'North End|-33.93|25.62',
        ],
        'East London|Eastern Cape' => [
            'Vincent|-32.98|27.90', 'Nahoon|-32.99|27.95', 'Beacon Bay|-32.96|27.93', 'Gonubie|-32.93|28.02', 'Quigney|-33.02|27.92',
        ],
        'Bloemfontein|Free State' => [
            'Westdene|-29.11|26.17', 'Langenhoven Park|-29.10|26.18', 'Brandwag|-29.10|26.20', 'Fichardt Park|-29.09|26.19', 'Willows|-29.14|26.19',
        ],
        'Polokwane|Limpopo' => [
            'Bendor|-23.91|29.50', 'Flora Park|-23.89|29.47', 'Fauna Park|-23.88|29.44', 'Nirvana|-23.92|29.47',
        ],
        'Pietermaritzburg|KwaZulu-Natal' => [
            'Scottsville|-29.62|30.40', 'Hilton|-29.55|30.30', 'Pelham|-29.63|30.41', 'Northdale|-29.59|30.36',
        ],
        'Mbombela|Mpumalanga' => [
            'Riverside|-25.45|30.96', 'West Acres|-25.46|30.93', 'Sonheuwel|-25.48|30.98', 'Kanyamazane|-25.52|31.20',
        ],
        'Rustenburg|North West' => [
            'Waterfall East|-25.64|27.25', 'Cashan|-25.65|27.20', 'Safari Gardens|-25.68|27.27', 'Karlien Park|-25.70|27.23',
        ],
        'Kimberley|Northern Cape' => [
            'Beaconsfield|-28.77|24.75', 'Hadison Park|-28.76|24.79', 'Galeshewe|-28.73|24.74', 'Monument Heights|-28.74|24.78',
        ],
    ],

];
