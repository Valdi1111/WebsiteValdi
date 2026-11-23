import AppRoot from "@CoreBundle/components/layout/AppRoot";
import ProfileApp from "@CoreBundle/components/profile/ProfileApp";
import { createRoot } from "react-dom/client";
import React from "react";

// Profile page (CoreBundle ProfileController), served on every subdomain
createRoot(document.getElementById('root')).render(<AppRoot><ProfileApp/></AppRoot>);
