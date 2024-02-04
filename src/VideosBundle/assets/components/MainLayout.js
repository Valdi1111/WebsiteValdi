import HeaderNav from "@CoreBundle/components/HeaderNav";
import HeaderNavItem from "@CoreBundle/components/HeaderNavItem";
import React from "react";

export default function MainLayout({children}) {

    return (
        <div className="d-flex flex-column vh-100">
            <HeaderNav
                navbar={<>
                    <HeaderNavItem path="/files" name="Files"/>
                    <HeaderNavItem path="/videos" name="Videos"/>
                </>}
                dropdown={
                    <li><span className="dropdown-item cursor-pointer">Prova 1</span></li>
                }
            />
            {children}
        </div>
    );

}