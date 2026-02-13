



       



        $(document).ready(function () {
            var gallery = $(".gallery-slider").owlCarousel({
                items: 3,
                margin: 20,
                loop: true,
                autoplay: true,
                autoplayTimeout: 2000,
                autoplayHoverPause: true,
                smartSpeed: 800,
                dots: false,
                nav: false,
                responsive: {
                    0: { items: 1 },
                    600: { items: 2 },
                    1000: { items: 3 }
                }
            });

            // Custom Navigation
            $('.gallery-prev').click(function () {
                gallery.trigger('prev.owl.carousel');
            });

            $('.gallery-next').click(function () {
                gallery.trigger('next.owl.carousel');
            });
        });


       

   
    document.addEventListener("DOMContentLoaded", function () {
        setTimeout(function () {
            var myModal = new bootstrap.Modal(document.getElementById('contactModal'));
            myModal.show();
        }, 2000); // 2 seconds
    });
