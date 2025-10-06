import "@BooksBundle/scss/image-view-modal.scss";
import AppRoot from "@CoreBundle/components/layout/AppRoot";
import App from "@BooksBundle/components/App";
import {createRoot} from "react-dom/client";
import React from "react";

const root = createRoot(document.getElementById('root'));
root.render(<AppRoot><App/></AppRoot>);