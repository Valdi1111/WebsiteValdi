import React from 'react';

export default function HeaderNav({navbar, dropdown}) {

    return (
        <nav className="navbar navbar-expand-md border-bottom">
            <div className="container-fluid">
                <button type="button" className="d-md-none btn btn-icon btn-outline-secondary" data-bs-toggle="collapse"
                        data-bs-target="#navigation" aria-controls="navigation" aria-expanded={false}
                        aria-label="Toggle navigation">
                    <i className="fa fa-bars"></i>
                </button>
                <div className="collapse navbar-collapse order-2 order-md-1" id="navigation">
                    <div className="d-md-none w-100 border-top my-2"/>
                    <ul className="nav flex-column flex-md-row nav-pills me-auto">
                        {navbar}
                    </ul>
                </div>
                <div className="dropdown order-1 order-md-2">
                    <div className="link-secondary dropdown-toggle cursor-pointer" data-bs-toggle="dropdown"
                         aria-expanded={false}>
                        <img src="/images/avatar.png" alt="Avatar" className="rounded-circle" width={40} height={40}/>
                    </div>
                    <ul className="dropdown-menu dropdown-menu-end">
                        {dropdown}
                        <li>
                            <hr className="dropdown-divider"/>
                        </li>
                        <li><a className="dropdown-item cursor-pointer" href="/logout">Sign out</a></li>
                    </ul>
                </div>
            </div>
        </nav>
    );

}