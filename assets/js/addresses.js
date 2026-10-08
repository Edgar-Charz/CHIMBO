/**
 * Anwani zangu: the list of addresses with "Weka kuu", "Badilisha" and "Futa", plus the shared form for
 * adding and editing. Every change returns the server's addresses, so the list is always redrawn from them.
 */
(() => {
    'use strict';

    const { api, el, icon, stateBlock, setLoading, toast, addressForm } = CHIMBO;

    const pageData = JSON.parse(document.getElementById('addresses-data').textContent);
    const list = document.querySelector('[data-address-list]');
    const addButton = document.querySelector('[data-address-add]');
    let addresses = pageData.addresses;

    function actionButton(label, iconName, onClick, className = 'btn btn-link') {
        const button = el('button', { className, attrs: { type: 'button' } }, [icon(iconName), ` ${label}`]);
        button.addEventListener('click', () => onClick(button));
        return button;
    }

    function renderAddress(address) {
        return el('article', { className: `address-card${address.address_is_default ? ' is-default' : ''}` }, [
            el('div', { className: 'address-card__top' }, [
                el('strong', { text: address.address_recipient_name }),
                address.address_is_default ? el('span', { className: 'choice-card__tag', text: 'Kuu' }) : null,
            ]),
            ...addressForm.describe(address).filter(Boolean).map((line) => el('p', { className: 'address-card__line', text: line })),
            el('div', { className: 'address-card__actions' }, [
                address.address_is_default ? null : actionButton('Weka kuu', 'star', (button) => makeDefault(address, button)),
                actionButton('Badilisha', 'pencil', () => openEditor(address)),
                actionButton('Futa', 'trash3', (button) => deleteAddress(address, button), 'btn btn-link address-card__delete'),
            ]),
        ]);
    }

    function renderList() {
        list.replaceChildren(...(addresses.length === 0
            ? [stateBlock({ iconName: 'geo-alt', title: 'Hakuna anwani bado', text: 'Ongeza anwani ya duka lako ili oda zifike haraka.' })]
            : addresses.map(renderAddress)));
    }

    /** Runs an action that answers with all the addresses, then redraws. */
    async function changeAddresses(button, send, message) {
        setLoading(button, true);
        try {
            addresses = await send();
            renderList();
            toast(message, 'success');
        } catch {
            setLoading(button, false); // the error toast was already shown
        }
    }

    const makeDefault = (address, button) => changeAddresses(button, () => api.post(`/addresses/${address.address_id}/default`), 'Anwani kuu imebadilishwa.');

    function deleteAddress(address, button) {
        if (!window.confirm(`Futa anwani ya ${address.address_recipient_name}?`)) return;
        changeAddresses(button, () => api.delete(`/addresses/${address.address_id}`), 'Anwani imefutwa.');
    }

    const editor = addressForm.attach(document.querySelector('[data-address-form]'), {
        suggested: pageData.suggested,
        onClose: () => { addButton.hidden = false; },
        onSaved: async (savedAddress) => {
            addresses = await api.get('/addresses'); // the default may have moved, so read the list again
            renderList();
            toast(`Anwani ya ${savedAddress.address_recipient_name} imehifadhiwa.`, 'success');
        },
    });

    async function openEditor(address = null) {
        addButton.hidden = true;
        await editor.open(address);
        document.querySelector('[data-address-form]').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    addButton.addEventListener('click', () => openEditor());

    renderList();
})();
