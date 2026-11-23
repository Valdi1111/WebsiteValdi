import CheckEmailMessage from '@CoreBundle/components/auth/CheckEmailMessage';
import ForgotPasswordForm from '@CoreBundle/components/auth/ForgotPasswordForm';
import ResetPasswordForm from '@CoreBundle/components/auth/ResetPasswordForm';
import LoginForm from '@CoreBundle/components/LoginForm';
import { createRoot } from 'react-dom/client';
import React from 'react';

// The "page" of @Core/security/auth_page.html.twig
const PAGES = {
    'login': LoginForm,
    'forgot-password': ForgotPasswordForm,
    'check-email': CheckEmailMessage,
    'reset-password': ResetPasswordForm,
};

const loginContainer = document.getElementById('react-login-app');
if (loginContainer) {
    const Page = PAGES[loginContainer.dataset.page] ?? LoginForm;
    const props = JSON.parse(loginContainer.dataset.props || '{}');
    const root = createRoot(loginContainer);
    root.render(<Page {...props} />);
}
