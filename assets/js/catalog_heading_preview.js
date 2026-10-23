(() => {
    const modalElement = document.querySelector('[data-catalog-preview]');
    const productsElement = document.getElementById('catalog-heading-products');

    if (!modalElement || !productsElement || !window.bootstrap?.Modal) return;

    let products;
    try {
        products = JSON.parse(productsElement.textContent);
    } catch {
        return;
    }

    if (!Array.isArray(products) || products.length === 0) return;

    const image = modalElement.querySelector('[data-preview-image]');
    const seller = modalElement.querySelector('[data-preview-seller]');
    const title = modalElement.querySelector('[data-preview-title]');
    const price = modalElement.querySelector('[data-preview-price]');
    const unit = modalElement.querySelector('[data-preview-unit]');
    const moq = modalElement.querySelector('[data-preview-moq]');
    const link = modalElement.querySelector('[data-preview-link]');
    const addButton = modalElement.querySelector('[data-preview-add-to-cart]');
    const addLabel = modalElement.querySelector('[data-preview-add-label]');
    const favorite = modalElement.querySelector('[data-preview-favorite]');
    const media = modalElement.querySelector('.catalog-preview__media');
    const galleryControls = modalElement.querySelector('[data-preview-gallery-controls]');
    const dots = modalElement.querySelector('[data-preview-dots]');
    const previousButton = modalElement.querySelector('[data-preview-previous]');
    const nextButton = modalElement.querySelector('[data-preview-next]');
    const swipeArea = modalElement.querySelector('[data-preview-swipe]');
    const modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
    const productGalleries = new Map();
    let browsingProducts = [];
    let currentIndex = 0;
    let currentProduct = null;
    let isAddingToCart = false;
    let activeGalleryRequest = 0;
    let galleryImages = [];
    let currentPhotoIndex = 0;
    let swipeStartX = null;
    let slideshowInterval = null;
    let slideshowPaused = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let isPointerOverGallery = false;
    let isTouchingGallery = false;
    let isGalleryControlFocused = false;

    const SLIDESHOW_INTERVAL_MS = 4000;

    const shuffle = (items) => {
        const shuffled = [...items];
        for (let index = shuffled.length - 1; index > 0; index -= 1) {
            const randomIndex = Math.floor(Math.random() * (index + 1));
            [shuffled[index], shuffled[randomIndex]] = [shuffled[randomIndex], shuffled[index]];
        }
        return shuffled;
    };

    const imageUrlForScreen = (photo) => {
        const isPhone = window.matchMedia('(max-width: 767px)').matches;
        return isPhone ? (photo.medium || photo.large) : (photo.large || photo.medium);
    };

    function preloadGalleryPhoto(index) {
        const photo = galleryImages[index];
        const imageUrl = photo ? imageUrlForScreen(photo) : '';
        if (!imageUrl) return;

        const preloadImage = new Image();
        preloadImage.decoding = 'async';
        preloadImage.src = imageUrl;
    }

    function clearSlideshow() {
        if (slideshowInterval !== null) window.clearInterval(slideshowInterval);
        slideshowInterval = null;
    }

    function startSlideshow() {
        clearSlideshow();
        if (
            slideshowPaused
            || isPointerOverGallery
            || isTouchingGallery
            || isGalleryControlFocused
            || galleryImages.length < 2
        ) return;
        slideshowInterval = window.setInterval(
            () => showGalleryPhoto(currentPhotoIndex + 1, true),
            SLIDESHOW_INTERVAL_MS,
        );
    }

    function updateAddButton() {
        const isAvailable = currentProduct?.product_in_stock === true;
        addButton.disabled = isAddingToCart || !isAvailable;
        addLabel.textContent = isAvailable
            ? `Ongeza kikapuni · ${window.CHIMBO.formatPieces(currentProduct.product_moq)}`
            : 'Imeisha';
    }

    const showProduct = (index) => {
        currentIndex = (index + browsingProducts.length) % browsingProducts.length;
        const product = browsingProducts[currentIndex];
        currentProduct = product;
        image.alt = product.product_name;
        seller.textContent = product.seller_name || '';
        title.textContent = product.product_name;
        price.textContent = window.CHIMBO.formatTzs(product.product_price_from);
        unit.textContent = ` / ${product.product_unit_label}`;
        moq.textContent = window.CHIMBO.formatPieces(product.product_moq);
        link.href = product.product_url;
        updateAddButton();
        favorite.replaceChildren(window.CHIMBO.wishlist.heartButton(product));
        const hasMoreProducts = browsingProducts.length > 1;
        previousButton.hidden = !hasMoreProducts;
        nextButton.hidden = !hasMoreProducts;
        showGallery(product, [product.product_image_url].filter(Boolean));
        loadProductGallery(product);
    };

    function showGallery(product, imageUrls) {
        clearSlideshow();
        galleryImages = imageUrls.length > 0
            ? imageUrls
            : (product.product_image_url ? [{ medium: product.product_image_url, large: product.product_image_url }] : []);
        currentPhotoIndex = 0;
        image.loading = 'eager';
        image.decoding = 'async';
        image.fetchPriority = 'high';
        image.src = galleryImages[0] ? imageUrlForScreen(galleryImages[0]) : '';
        galleryControls.hidden = galleryImages.length < 2;
        dots.replaceChildren(...galleryImages.map((imageUrl, index) => {
            const dot = window.CHIMBO.el('button', {
                className: `catalog-preview__dot${index === currentPhotoIndex ? ' is-active' : ''}`,
                attrs: {
                    type: 'button',
                    'aria-label': `Picha ${index + 1} kati ya ${galleryImages.length}`,
                    'aria-pressed': String(index === currentPhotoIndex),
                },
            });
            dot.addEventListener('click', () => showGalleryPhoto(index));
            return dot;
        }));
        preloadGalleryPhoto(1);
        startSlideshow();
    }

    function showGalleryPhoto(index, isAutomatic = false) {
        if (galleryImages.length < 2) return;
        currentPhotoIndex = (index + galleryImages.length) % galleryImages.length;
        image.src = imageUrlForScreen(galleryImages[currentPhotoIndex]);
        dots.querySelectorAll('.catalog-preview__dot').forEach((dot, dotIndex) => {
            const isActive = dotIndex === currentPhotoIndex;
            dot.classList.toggle('is-active', isActive);
            dot.setAttribute('aria-pressed', String(isActive));
        });
        preloadGalleryPhoto((currentPhotoIndex + 1) % galleryImages.length);
        if (!isAutomatic) startSlideshow();
    }

    async function loadProductGallery(product) {
        const requestId = ++activeGalleryRequest;
        const cachedImages = productGalleries.get(product.product_id);
        if (cachedImages) {
            showGallery(product, cachedImages);
            return;
        }

        try {
            const details = await window.CHIMBO.api.get(`/products/${product.product_id}`, { silent: true });
            const imageSources = Array.isArray(details.gallery)
                ? details.gallery.map((photo) => ({
                    medium: photo.product_image_medium_url || '',
                    large: photo.product_image_large_url || '',
                }))
                : (Array.isArray(details.images) ? details.images.map((imageUrl) => ({ medium: imageUrl, large: imageUrl })) : []);
            const uniqueImageSources = imageSources.filter((photo, index, photos) =>
                (photo.medium || photo.large)
                && photos.findIndex((candidate) => candidate.medium === photo.medium && candidate.large === photo.large) === index
            );
            productGalleries.set(product.product_id, uniqueImageSources);

            if (requestId === activeGalleryRequest) showGallery(product, uniqueImageSources);
        } catch {
            // The banner photo remains available if the optional product gallery cannot load.
        }
    }

    document.querySelectorAll('[data-heading-product-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const selectedProduct = products[Number(button.dataset.headingProductOpen)];
            if (!selectedProduct) return;

            slideshowPaused = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            browsingProducts = [selectedProduct, ...shuffle(products.filter((product) => product !== selectedProduct))];
            showProduct(0);
            modal.show();
        });
    });

    addButton.addEventListener('click', async () => {
        const product = currentProduct;
        if (!product || !product.product_in_stock || isAddingToCart) return;

        isAddingToCart = true;
        window.CHIMBO.setLoading(addButton, true);
        try {
            await window.CHIMBO.cart.add(product.product_id, product.product_moq);
        } catch {
            // The cart flow already displays its error message.
        } finally {
            isAddingToCart = false;
            window.CHIMBO.setLoading(addButton, false);
            updateAddButton();
        }
    });

    previousButton.addEventListener('click', () => showProduct(currentIndex - 1));
    nextButton.addEventListener('click', () => showProduct(currentIndex + 1));

    media.addEventListener('pointerenter', (event) => {
        if (event.pointerType !== 'mouse') return;
        isPointerOverGallery = true;
        clearSlideshow();
    });
    media.addEventListener('pointerleave', (event) => {
        if (event.pointerType !== 'mouse') return;
        isPointerOverGallery = false;
        startSlideshow();
    });
    media.addEventListener('pointerdown', (event) => {
        if (event.pointerType !== 'touch') return;
        isTouchingGallery = true;
        clearSlideshow();
    });
    media.addEventListener('pointerup', (event) => {
        if (event.pointerType !== 'touch') return;
        isTouchingGallery = false;
        startSlideshow();
    });
    media.addEventListener('pointercancel', (event) => {
        if (event.pointerType !== 'touch') return;
        isTouchingGallery = false;
        startSlideshow();
    });
    galleryControls.addEventListener('focusin', () => {
        isGalleryControlFocused = true;
        clearSlideshow();
    });
    galleryControls.addEventListener('focusout', (event) => {
        if (galleryControls.contains(event.relatedTarget)) return;
        isGalleryControlFocused = false;
        startSlideshow();
    });

    swipeArea.addEventListener('touchstart', (event) => {
        swipeStartX = event.changedTouches[0]?.clientX ?? null;
    }, { passive: true });

    swipeArea.addEventListener('touchend', (event) => {
        if (swipeStartX === null) return;
        const swipeDistance = event.changedTouches[0].clientX - swipeStartX;
        swipeStartX = null;
        if (Math.abs(swipeDistance) < 48 || galleryImages.length < 2) return;
        showGalleryPhoto(currentPhotoIndex + (swipeDistance < 0 ? 1 : -1));
    }, { passive: true });

    modalElement.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' && browsingProducts.length > 1) showProduct(currentIndex - 1);
        if (event.key === 'ArrowRight' && browsingProducts.length > 1) showProduct(currentIndex + 1);
    });

    modalElement.addEventListener('hidden.bs.modal', () => {
        activeGalleryRequest += 1;
        clearSlideshow();
        isPointerOverGallery = false;
        isTouchingGallery = false;
        isGalleryControlFocused = false;
    });
})();
