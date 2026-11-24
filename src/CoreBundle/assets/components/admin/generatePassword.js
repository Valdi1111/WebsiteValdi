// Characters easy to read aloud or copy by hand: no 0/O/o, 1/l/I, quotes, spaces or brackets
const CHARACTER_CLASSES = [
    'abcdefghijkmnpqrstuvwxyz',
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    '23456789',
    '!#$%&*+-=?@^_',
];
const ALPHABET = CHARACTER_CLASSES.join('');

/**
 * A uniformly random index in [0, size) from the browser CSPRNG. The bytes from the largest multiple of size
 * upward are discarded (rejection sampling), so the modulo does not favour the first characters.
 *
 * @param {number} size at most 256
 * @returns {number}
 */
function randomIndex(size) {
    const limit = 256 - (256 % size);
    const bytes = new Uint8Array(1);
    do {
        crypto.getRandomValues(bytes);
    } while (bytes[0] >= limit);

    return bytes[0] % size;
}

/**
 * A random password for a new user, with at least a lowercase and an uppercase letter, a digit and a symbol.
 * The whole password is drawn again until it has them all, instead of forcing some positions, so every
 * password of that kind is equally likely.
 *
 * @param {number} length
 * @returns {string}
 */
export default function generatePassword(length = 20) {
    for (;;) {
        const password = Array.from({ length }, () => ALPHABET[randomIndex(ALPHABET.length)]).join('');
        if (CHARACTER_CLASSES.every(characters => [...password].some(c => characters.includes(c)))) {
            return password;
        }
    }
}
