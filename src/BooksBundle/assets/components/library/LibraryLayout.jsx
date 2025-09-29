import { PlusOutlined } from "@ant-design/icons";
import BookAddModal from "@BooksBundle/components/library/BookAddModal";
import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { Link } from "react-router-dom";
import React from "react";

export default function LibraryLayout({ children }) {
    const [addOpen, setAddOpen] = React.useState(false);

    return <PageLayout
        navbarItems={[
            {
                key: 1,
                label: <Link to="/library/all">All books</Link>,
            },
            {
                key: 2,
                label: <Link to="/library/shelves">Shelves</Link>,
            },
            {
                key: 3,
                label: <Link to="/library/not-in-shelves">Not in shelves</Link>,
            },
            {
                key: 4,
                label: <Link to="/files">Files</Link>,
            },
        ]}
        dropdownItems={[
            {
                key: 'addBook',
                label: 'Add book',
                icon: <PlusOutlined/>,
                onClick: () => setAddOpen(true)
            },
        ]}
        childrenPre={<BookAddModal open={addOpen} setOpen={setAddOpen}/>}
        children={children}
    />;

}
