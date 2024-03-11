// Theme constants
export const THEME = 'theme';

export const THEMES = {
    light: {
        name: 'Light',
        css: {
            body: { background: '#fff', color: '#212529' },
            'a:link': { color: '#0d6efd' }
        },
        webix: 'material.css',
    },
    dark: {
        name: 'Dark',
        css: {
            body: { background: '#212529', color: '#dee2e6' },
            'a:link': { color: '#6ea8fe' }
        },
        webix: 'dark.css',
    },
    //light: {
    //    name: 'Light',
    //    css: {
    //        body: { background: '#fff', color: '#000' },
    //        'a:link': { color: 'blue' }
    //    }
    //},
    //dark: {
    //    name: 'Dark',
    //    css: {
    //        body: { background: '#292929', color: '#dedede' },
    //        'a:link': { color: 'cornflowerblue' }
    //    }
    //},
    //sepia: {
    //    name: 'Sepia',
    //    css: {
    //        body: { background: '#efe7dd', color: '#5b4636' },
    //        'a:link': { color: 'darkcyan' }
    //    }
    //},
    //nord: {
    //    name: 'Nord',
    //    css: {
    //        body: { background: '#2e3440', color: '#d8dee9' },
    //        'a:link': { color: '#88c0d0' }
    //    }
    //}
}
