import { BookOutlined, FolderOpenOutlined, GroupOutlined, PlusOutlined } from "@ant-design/icons";
import BookAddModal from "@BooksBundle/components/library/BookAddModal";
import PageLayout from "@CoreBundle/components/layout/PageLayout";
import { Link } from "react-router-dom";
import React from "react";

export default function MainLayout({ children }) {
    const [addOpen, setAddOpen] = React.useState(false);

    return <PageLayout
        navbarItems={[
            {
                key: 1,
                label: <Link to="/library/all">All books</Link>,
                pathname_regex: /^\/library\/all$/,
                icon: <BookOutlined/>
            },
            {
                key: 2,
                label: <Link to="/library/shelves">Shelves</Link>,
                pathname_regex: /^\/library\/shelves(?:\/\d+)?$/,
                icon: <GroupOutlined/>
            },
            {
                key: 3,
                label: <Link to="/library/not-in-shelves">Not in shelves</Link>,
                pathname_regex: /^\/library\/not-in-shelves$/,
                icon: <BookOutlined/>
            },
            {
                key: 4,
                label: <Link to="/files">Files</Link>,
                pathname_regex: /^\/files$/,
                icon: <FolderOpenOutlined/>
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
        childrenPre={<BookAddModal visible={addOpen} setVisible={setAddOpen}/>}
        children={children}
    />;

}
