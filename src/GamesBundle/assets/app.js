import AppRoot from "@CoreBundle/components/layout/AppRoot";
import App from "@GamesBundle/components/App";
import allProviders from "@GamesBundle/providers";
import { createRoot } from "react-dom/client";
import React from "react";

const rootElement = document.getElementById('root');

// Provider keys granted to the current user, in tab order (rendered by GamesBundle IndexController)
let grantedKeys = [];
try {
    grantedKeys = JSON.parse(rootElement.dataset.providers || '[]');
} catch {
    grantedKeys = [];
}
const providers = grantedKeys
    .map((key) => allProviders.find((provider) => provider.key === key))
    .filter(Boolean);

createRoot(rootElement).render(<AppRoot><App providers={providers}/></AppRoot>);
