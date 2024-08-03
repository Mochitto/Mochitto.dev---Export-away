# Excel in Exporting Huge Files: A Scalable Solution for Large XLSX Exports

While working as a full-stack developer, one of the companies I worked for struggled with exporting huge XLSX files.
The customer needed XLSX files, and this was non-negotiable for them, as their data management team depended on that file format.

Initially, the company's solution was adequate, but as the project grew, it led to out-of-memory (OOM) issues and long processing times, significantly hindering development efficiency.

I was tasked with studying the architecture of the app and finding a scalable solution.

In this blog post, I'll explain the underlying problems and the scalable solution I developed. I've also replicated the process in this repository with additional optimizations, enabling the export of XLSX files with 10,000,000 cells in about 10 minutes.

### Understanding the Original Problem

The project utilized PostgreSQL, Angular, and NestJS and faced several bottlenecks and poor optimizations:

1. **Inefficient ORM Queries**
    - Multiple ORM queries were used to avoid complex queries, leading to multiple database trips for each row of XLSX data.
    For 1000 rows, this meant 3000+ trips to the database.
2. **Memory Overload**
    - Data was retrieved from the database, processed in the backend, and stored in an array. This meant keeping the data in two or three places in memory: the original SQL data, the processed data in an array, and the data inside the XLSX object.
3. **Inefficient XLSX Library**
    - The library used to build XLSX files caused RAM spikes up to 10 times the final file size during row handling.

## The Solutions

These solutions were applied in the original project.

### 1. Optimized Raw SQL Queries

The first problem I tackled was the queries. Using subqueries and a complex query leveraging PostgreSQL optimizations seemed like a great way to reduce processing times. 
We went from needing up to hours for an export to a few minutes.
Instead of having multiple database accesses per row, there was now only one access per sheet.

An example can be found in `query-commissions.php` at `createTempView`.

### 2. Leveraging Generators and PostgreSQL Functions

Another problem was the way we were assembling data. TypeScript is not a very performant language, and there were two ways to improve performance:

1. **PostgreSQL Functions**: Having PostgreSQL do as much of the work as possible. This is achievable by using PostgreSQL's functions and aggregations.
    An example can be found in `query-commissions.php` at `createTempView`.

2. **Generators**: Using generators to free up RAM memory with each processed row.
    Generators "generate" data, allowing the language's garbage collection system to free up that memory.
    Functionally, they are used to iterate through a set of data. Unlike arrays, this data is processed on demand and then discarded. An example can be found in `query-commissions.php` at `generateFormattedCsvRows`.

### Working Around the Library - Streams

Generators are useful only when you have a way to use only one value at a time. Pushing the data to an array would cause the same problem we are trying to solve. There is a type of file similar to XLSX that allows for streaming: CSV files.

The library handled loading CSV files and surprisingly used much less RAM this way. We could process the data, write it row by row to a CSV file on the hard disk, and then convert it into an XLSX file at the end, ready to be zipped and uploaded.

An example can be found in `index.php` at `createShopsCsvFiles`.

### The Final Pipeline

The final process would look like this:

1. **Cron Job Initiation**: A cron job triggers the file export function.
2. **Data Retrieval**: All the data is gathered from the database in one trip, formatted as much as possible by PostgreSQL.
3. **Row Processing**: We manipulate each row using a generator that formats the row, freeing up the memory taken by it as soon as it's no longer needed.
4. **CSV File Creation**: We write these rows to a CSV file using NodeJS's streaming features.
5. **XLSX File Generation**: We read the CSV file with the XLSX library, zip it, and send it to S3.

## The Result

The new process handled up to 10 times the amount of data we had at the time, ensuring scalability for years to come. It now takes about 10 minutes, instead of the previous two hours, meaning fewer resources are needed, and the development experience is faster, increasing productivity.

## Excelling at Exporting Excel Files

Further optimizations could be applied by:

- **"Streaming" Data from the Database**: By breaking it up into chunks, we avoid running out of memory regardless of the dataset size. An example can be found in `query-commissions.php` at `getShopCommissions`.

- **Using More Performant Languages**: Instead of writing the XLSX using JavaScript or a slow language, we can call a script in a performant language, just as we would with a function. An example can be found in `index.php` at `csvToXlsx`.

### The Implementation

The project uses Docker to handle the server and database, `make` to improve the dev experience, PHP as our backend language, and [csv2xlsx](https://github.com/mentax/csv2xlsx), a Go library that converts CSV files into XLSX. I've also replaced the zip library with `tar`, as its performance is very good and it comes pre-installed in the Docker image.

#### The Use Case

This is a demo of an app for business owners, allowing them to monitor their shops. Another special feature is enabling users to make a commission with friends, so we need to aggregate data from multiple tables to show who took part in a commission and their reviews.

We export all of the commissions that occurred in all of the shops of a given owner, totaling 500,000 rows (with 10 columns) per shop. The process probably has much higher capabilities, but I was satisfied with this result.

#### The New Pipeline

1. **Select Shop ID**: Landing at `localhost:3000/html/index.php`, you can select the ID of a shop.
2. **Request Export**: Make a POST request with the ID of the shop's owner.
3. **Create Temporary Folders**: Create temporary folders for handling the files needed for the export. This folder uses the current timestamp to ensure uniqueness, allowing multiple exports to occur simultaneously.
4. **Create a Temporary View**: Create a temporary view of the complete data we want to export. This makes subsequent accesses to the data quick and opens the door to breaking up our data into chunks and streaming it to the backend.
5. **Process Data in Chunks**: Retrieve chunks of 100,000 rows using a generator; push the rows one by one through another generator to format them and then stream them to disk in a CSV file.
6. **Generate XLSX File**: Use the Go library `csv2xlsx` to obtain an XLSX file containing a sheet for each shop, each containing all of its commissions.
7. **Zip and Send the File**: Use `tar` to zip the file efficiently and send it to the client.

## Building the Project

### Dependencies

You need to have `make` and `docker` installed on your machine.

Docker will take care of installing PostgreSQL and PHP (along the GO library dependency).

### Starting the project

You can start the project by calling
```bash
make
```

This will bring up the containers and seed the database.
You can monitor for the database status by running:
```bash
docker compose logs -f database
```

After seeding has completed, you can visit `localhost:3000/html/index.php`, select the id and run the export. It will take a bit of time depending on your machine (11 minutes on my machine) and download the file once its done.

When you want to shut down the process, you can press 'q' or run from another terminal
```bash
make stop
```

You can look at the folder where `index.php` is located to see the process at work, building temporary folders and files, putting them together, zipping and then cleaning up.

### Extra scripts
You can use the scripts in `/bin` to inspect the database.
```
./bin/connect-db.sh
```
This calls `psql` under the hood to connect you to the db used in the app.

## Extra considerations regarding further improvements
Since we are breaking the data coming from the DB into chunks and streaming it to disk, the current bottlenecks are the csv2xlsx library (if it doesn't stream data to the xlsx file) and PostgreSQL's memory.
