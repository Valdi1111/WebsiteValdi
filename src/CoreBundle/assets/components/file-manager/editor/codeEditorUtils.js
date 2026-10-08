/**
 * Map file extension or filename to a CodeMirror language-data name.
 *
 * @param {string} extension
 * @param {string} filename
 * @returns {string|null}
 */
export function getLanguageName(extension, filename = '') {
    // Dotfiles and special configuration files
    if (filename.startsWith('.env') || filename === '.plexmatch') {
        return 'ini';
    }

    const ext = extension?.toLowerCase();
    switch (ext) {
        case 'js':
        case 'jsx':
            return 'javascript';
        case 'ts':
        case 'tsx':
            return 'typescript';
        case 'json':
            return 'json';
        case 'html':
        case 'htm':
            return 'html';
        case 'css':
            return 'css';
        case 'scss':
        case 'sass':
        case 'less':
            return 'css';
        case 'php':
            return 'php';
        case 'sh':
            return 'shell';
        case 'md':
            return 'markdown';
        case 'yml':
        case 'yaml':
            return 'yaml';
        case 'sql':
            return 'sql';
        case 'xml':
            return 'xml';
        default:
            return null; // Fallback to plain text without syntax highlighting
    }
}
