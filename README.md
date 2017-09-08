# GetSwift Code Test

This is my solution to the [GetSwift code test](https://github.com/GetSwift/codetest/)
A live version of this can be found [here](http://getswifttest.dennen.software/)


## Analysis

I implemented my solution using a relatively straight forward PHP script. Once retrieving the data from the specified API, I sorted the list of packages and the list of drones so that when assigning pairs the process would be more straight forward. To sort the packages I calculated the time that they absolutely needed to leave the depo by and sorted them in ascending order. As such, packages that needed to leave the depo soonest appeared first in the list. To sort the drones, I calculated the time each would be back at the depo, and sorted such that those back sooner would appear first in the list. Once the two lists were sorted, I simply looped through the list of packages to assign a drone to them. Because both lists were already sorted, if the drone at the current index in the drone list could not deliver the package in time, we know that no other available drone could deliver the package either, thus meaning the package goes to the unassigned list.

The reason I chose this solution is that it seemed like a relatively straight forward one. I didn’t need to majorly modify or reformat the data besides sorting it and the sorting of the data before assignment made the most sense to me logically. 

The solution I’ve implemented would most likely be very slow with that amount of data. In particular, the part of my solution that uses the Haversine formula to calculate distance uses a lot of computing power. That would be the first step I take in optimizing the code. Beyond that, it would liekly be best to have some sort of a system where the work is distributed. 