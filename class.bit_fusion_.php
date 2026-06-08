<?php
/**
 * Yann Bit Fusion – version finale
 * Encode / decode Unicode <-> bit_fusion (phase complexe)
 * Nécessite l’extension intl (IntlChar)
 */

class BitFusion {
    public float $theta; // phase en radians
    public float $real;  // cos(theta)
    public float $imag;  // sin(theta)

    public function __construct(float $theta) {
        $this->theta = $theta;
        $this->real  = cos($theta);
        $this->imag  = sin($theta);
    }
}

/**
 * Encode un codepoint Unicode en bit_fusion (phase sur le cercle unité)
 */
function encode_unicode_to_bitfusion(int $codepoint): BitFusion {
    $N = 0x110000; // 1 114 112
    $theta = 2 * M_PI * ($codepoint / $N);
    return new BitFusion($theta);
}

/**
 * Décodage robuste : bit_fusion -> codepoint Unicode
 */
function decode_bitfusion_robust(BitFusion $b): int {
    $N = 0x110000;

    // Phase brute
    $theta = atan2($b->imag, $b->real);
    if ($theta < 0) {
        $theta += 2 * M_PI;
    }

    // Position théorique
    $pos = ($theta / (2 * M_PI)) * $N;

    // Arrondi + wrap
    $c = (int) round($pos);
    $c = $c % $N;

    return $c;
}

/**
 * String -> tableau de bit_fusion
 */
function string_to_bitfusion_array(string $str): array {
    $bits = [];
    $len = mb_strlen($str);

    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($str, $i, 1);
        $code = IntlChar::ord($char);
        $bits[] = encode_unicode_to_bitfusion($code);
    }

    return $bits;
}

/**
 * Tableau de bit_fusion -> string
 */
function bitfusion_array_to_string(array $bits): string {
    $out = "";

    foreach ($bits as $b) {
        $code = decode_bitfusion_robust($b);
        $out .= IntlChar::chr($code);
    }

    return $out;
}

/**
 * Méga-fusion stable (sans big integers) :
 * encode une chaîne en un bit_fusion "moyen" (profil global)
 * -> non réversible exactement, mais utile comme signature/vibration
 */
function encode_string_megafusion(string $str): BitFusion {
    $N = 0x110000;
    $L = mb_strlen($str);

    if ($L === 0) {
        // chaîne vide -> phase 0
        return new BitFusion(0.0);
    }

    $sum = 0.0;

    for ($i = 0; $i < $L; $i++) {
        $char = mb_substr($str, $i, 1);
        $code = IntlChar::ord($char);
        $sum += $code / $N;
    }

    $norm = $sum / $L;          // moyenne normalisée dans [0,1)
    $theta = 2 * M_PI * $norm;  // phase

    return new BitFusion($theta);
}

/**
 * Décoder une méga-fusion en "caractère moyen" (profil)
 * -> pas la chaîne originale, mais un symbole représentatif
 */
function decode_megafusion_to_char(BitFusion $b): string {
    $N = 0x110000;

    $theta = atan2($b->imag, $b->real);
    if ($theta < 0) {
        $theta += 2 * M_PI;
    }

    $norm = $theta / (2 * M_PI);
    $code = (int) round($norm * $N);

    return IntlChar::chr($code % $N);
}
/**
 * Compression exacte : string -> big integer (base Unicode)
 * Nécessite l’extension GMP.
 */
function encode_string_exact_megafusion(string $str): array {
    $N = 0x110000;
    $L = mb_strlen($str);

    $value = gmp_init(0);

    for ($i = 0; $i < $L; $i++) {
        $char = mb_substr($str, $i, 1);
        $code = IntlChar::ord($char);
        $value = gmp_add(gmp_mul($value, $N), $code);
    }

    return [
        'length' => $L,
        'value'  => $value,
    ];
}

/**
 * Décompression exacte : big integer -> string
 */
function decode_string_exact_megafusion(array $compressed): string {
    $N = 0x110000;
    $L = $compressed['length'];
    $value = $compressed['value'];

    $chars = "";

    for ($i = 0; $i < $L; $i++) {
        $code = gmp_intval(gmp_mod($value, $N));
        $value = gmp_div_q($value, $N);
        $chars = IntlChar::chr($code) . $chars;
    }

    return $chars;
}

/**
 * Visualisation : big integer compressé -> BitFusion (approximation)
 */
function compressed_to_bitfusion(array $compressed): BitFusion {
    $N = 0x110000;
    $L = $compressed['length'];

    $max = gmp_pow($N, $L);
    $valueStr = gmp_strval($compressed['value']);
    $maxStr   = gmp_strval($max);

    // Approximation float (pour visualisation, pas pour la réversibilité)
    $norm  = (float)$valueStr / (float)$maxStr;
    $theta = 2 * M_PI * $norm;

    return new BitFusion($theta);
}
/**
 * Convertit une clé en phase dans [0, 2π)
 */
function key_to_phase(string $key): float {
    $hash = hash('sha256', $key, true);
    $part = substr($hash, 0, 8);
    $arr  = unpack('Jint', $part); // 64 bits
    $int  = $arr['int'];

    $norm = ($int & 0xFFFFFFFFFFFF) / 0xFFFFFFFFFFFF;
    return 2 * M_PI * $norm;
}

/**
 * Chiffrement : rotation de phase
 */
function encrypt_bitfusion(BitFusion $b, string $key): BitFusion {
    $phi   = key_to_phase($key);
    $theta = fmod($b->theta + $phi, 2 * M_PI);
    return new BitFusion($theta);
}

/**
 * Déchiffrement : rotation inverse
 */
function decrypt_bitfusion(BitFusion $b, string $key): BitFusion {
    $phi   = key_to_phase($key);
    $theta = fmod($b->theta - $phi + 2 * M_PI, 2 * M_PI);
    return new BitFusion($theta);
}
/**
 * Sauvegarde une chaîne en .ybf (compression exacte)
 */
function ybf_save(string $str, string $path): void {
    $compressed = encode_string_exact_megafusion($str);

    $data = [
        'length' => $compressed['length'],
        'value'  => gmp_strval($compressed['value']),
    ];

    file_put_contents($path, json_encode($data));
}

/**
 * Charge un fichier .ybf et reconstruit la chaîne
 */
function ybf_load(string $path): string {
    $json = file_get_contents($path);
    $data = json_decode($json, true);

    $compressed = [
        'length' => $data['length'],
        'value'  => gmp_init($data['value']),
    ];

    return decode_string_exact_megafusion($compressed);
}
/**
 * Génère un SVG montrant les points bit_fusion d'une chaîne
 */
function render_bitfusion_svg(string $str): string {
    $bits = string_to_bitfusion_array($str);

    $width  = 300;
    $height = 300;
    $cx = $width / 2;
    $cy = $height / 2;
    $r  = 100;

    $svg  = '<svg width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="#444" stroke-width="1"/>';

    foreach ($bits as $i => $b) {
        $x = $cx + $r * $b->real;
        $y = $cy - $r * $b->imag;
        $svg .= '<circle cx="'.$x.'" cy="'.$y.'" r="3" fill="#ff6600">';
        $svg .= '</circle>';
    }

    $svg .= '</svg>';

    return $svg;
}

function ybf_log_event(string $event, string $logDir = 'logs'): void {
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $ts   = date('Ymd_His');
    $file = $logDir.'/event_'.$ts.'.ybf';

    ybf_save($event, $file); // utilise déjà ton encodeur .ybf
}

ybf_log_event("BOOT:YannOS kernel started");
ybf_log_event("STATE:bit_fusion OK");


function render_states_svg(array $states): string {
    $width = $height = 400;
    $cx = $cy = 200;
    $r  = 140;

    $svg  = '<svg width="'.$width.'" height="'.$height.'" viewBox="0 0 '.$width.' '.$height.'" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="#444" stroke-width="1"/>';

    foreach ($states as $state) {
        $bit = encode_string_megafusion($state); // déjà défini chez toi
        $x = $cx + $r * $bit->real;
        $y = $cy - $r * $bit->imag;
        $svg .= '<circle cx="'.$x.'" cy="'.$y.'" r="4" fill="#ff6600">';
        $svg .= '<title>'.htmlspecialchars($state).'</title>';
        $svg .= '</circle>';
    }

    $svg .= '</svg>';
    return $svg;
}

$states = ["BOOT", "IDLE", "FUSION:OK", "ALERT:LOW_ENERGY"];
echo render_states_svg($states);


function encrypt_message_runique(string $msg, string $key): BitFusion {
    $bit = encode_string_megafusion($msg);
    return encrypt_bitfusion($bit, $key); // ta fonction de rotation de phase
}

function decrypt_message_runique(BitFusion $b, string $key): string {
    $plainBit = decrypt_bitfusion($b, $key);
    return decode_megafusion_to_char($plainBit); // symbole profil
}
$secret = encrypt_message_runique("ᛇ‑FUSION‑OK", "Q{Mm&Qyhhw0:wix7#NYE6dzcQ2"); //Change by your secret key
// stockage / envoi…

echo decrypt_message_runique($secret, "Q{Mm&Qyhhw0:wix7#NYE6dzcQ2"); //Change by your secret key


function bitfusion_packet_encode(string $msg, string $mode = 'plain', ?string $key = null): string {
    if ($mode === 'encrypted' && $key !== null) {
        $bit = encrypt_message_runique($msg, $key);
    } else {
        $bit = encode_string_megafusion($msg);
    }

    $packet = [
        'theta' => $bit->theta,
        'mode'  => $mode,
    ];

    return json_encode($packet);
}

function bitfusion_packet_decode(string $packetJson, ?string $key = null): string {
    $data = json_decode($packetJson, true);
    $bit  = new BitFusion($data['theta']);

    if ($data['mode'] === 'encrypted' && $key !== null) {
        return decrypt_message_runique($bit, $key);
    }

    return decode_megafusion_to_char($bit);
}

$pkt = bitfusion_packet_encode("ᛇ‑HELLO‑YANNOS", 'encrypted', 'ma_cle_secrete');
// … envoyer $pkt …

$recv = bitfusion_packet_decode($pkt, 'ma_cle_secrete');
echo $recv;


echo render_bitfusion_svg("YannOS Fusion");
/* ===========================
 *        TESTS RAPIDES
 * =========================== */

echo "<pre>";

// 1) Test caractère simple
$char = "ᛇ";
$code = IntlChar::ord($char);

$bit = encode_unicode_to_bitfusion($code);
$decoded = decode_bitfusion_robust($bit);

echo "=== Test caractère ===\n";
echo "Original : $char\n";
echo "Codepoint: $code\n";
echo "Fusion   : (".$bit->real.", ".$bit->imag.")\n";
echo "Decoded  : ".IntlChar::chr($decoded)."\n\n";

// 2) Test string <-> tableau de bit_fusion
$str = "YannOS Fusion";
$bits = string_to_bitfusion_array($str);

echo "=== String -> bit_fusion[] ===\n";
echo "String : $str\n";
foreach ($bits as $i => $b) {
    echo "Char $i : (".$b->real.", ".$b->imag.")\n";
}

$decodedStr = bitfusion_array_to_string($bits);
echo "\n=== bit_fusion[] -> String ===\n";
echo "Decoded string : $decodedStr\n\n";

// 3) Test méga-fusion
$str2 = "PatateQuantique";
$mega = encode_string_megafusion($str2);
$megaChar = decode_megafusion_to_char($mega);

echo "=== Méga-fusion ===\n";
echo "String       : $str2\n";
echo "MegaFusion   : (".$mega->real.", ".$mega->imag.")\n";
echo "Mega decoded : $megaChar\n";

echo "</pre>";

?>
