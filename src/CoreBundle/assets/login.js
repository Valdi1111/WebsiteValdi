import LoginForm from '@CoreBundle/components/LoginForm';
import { createRoot } from 'react-dom/client';
import React from 'react';

const loginContainer = document.getElementById('react-login-app');
if (loginContainer) {
    const props = JSON.parse(loginContainer.dataset.props || '{}');
    const root = createRoot(loginContainer);
    root.render(<LoginForm {...props} />);
}
