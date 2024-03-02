import { deleteShelf } from "@BooksBundle/api/shelves";
import React from "react";

export default function ShelfDeleteModal({ update }) {
    const [id, setId] = React.useState(null);
    const [name, setName] = React.useState('');
    const btn = React.useRef();
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("show.bs.modal", (e) => {
            setId(e.relatedTarget.getAttribute("data-bs-id"));
            setName(e.relatedTarget.getAttribute("data-bs-name"));
        });
        modal.current.addEventListener("shown.bs.modal", (e) => {
            btn.current.focus();
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            setId(null);
            setName('');
        });
    }, []);

    function confirm() {
        if (!id) {
            return;
        }
        deleteShelf(id).then(
            res => update(res.data),
            err => console.error(err)
        );
    }

    return (
        <div className="modal fade" id="shelf-delete-modal" tabIndex={-1} aria-hidden={true}
             aria-labelledby="shelf-delete-modal-label" ref={modal}>
            <div className="modal-dialog">
                <div className="modal-content">
                    <div className="modal-header">
                        <h5 className="modal-title" id="shelf-delete-modal-label">Confirm shelf deletion</h5>
                        <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"/>
                    </div>
                    <div className="modal-body">
                        <h6>{name}</h6>
                        <p className="mb-0">Are you sure you want to delete this shelf?</p>
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
