import { deleteBook } from "@BooksBundle/api/book";
import React from "react";

export default function BookDeleteModal() {
    const [id, setId] = React.useState(null);
    const [title, setTitle] = React.useState('');
    const btn = React.useRef();
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("show.bs.modal", (e) => {
            setId(e.relatedTarget.getAttribute("data-bs-id"));
            setTitle(e.relatedTarget.getAttribute("data-bs-title"));
        });
        modal.current.addEventListener("shown.bs.modal", (e) => {
            btn.current.focus();
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            setId(null);
            setTitle("");
        });
    }, []);

    function confirm() {
        if (!id) {
            return;
        }
        deleteBook(id).then(
            res => {
                console.log("Book", id, "Deleted successfully!");
                //update(id);
            },
            err => console.error(err)
        );
    }

    return (
        <div className="modal fade" id="book-delete-modal" tabIndex={-1} aria-hidden={true}
             aria-labelledby="book-delete-modal-label" ref={modal}>
            <div className="modal-dialog">
                <div className="modal-content">
                    <div className="modal-header">
                        <h5 className="modal-title" id="book-delete-modal-label">Confirm book deletion</h5>
                        <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"/>
                    </div>
                    <div className="modal-body">
                        <h6>{title}</h6>
                        <p className="mb-0">Are you sure you want to delete this book?</p>
                        <p>This process cannot be undone.</p>
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-danger" data-bs-dismiss="modal">
                            Close
                        </button>
                        <button type="button" className="btn btn-primary" data-bs-dismiss="modal" onClick={confirm}
                                ref={btn}>
                            Confirm
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
